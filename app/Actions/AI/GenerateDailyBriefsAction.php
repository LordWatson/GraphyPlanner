<?php

namespace App\Actions\AI;

use App\Enums\CampaignStatus;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Mail\DailyBriefReadyMail;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The Step 2.5 nightly job: for every active client, calls DiscoverTrendsAction/
 * RecommendPostsAction and stores an internal-only "Daily Brief" (top trends + 3-5 fresh post
 * ideas tied to the client's active campaigns) for that calendar day, then emails a summary to
 * the client's organization Owners/Strategists — unless the current time falls inside the
 * configured quiet hours window, in which case the brief is still generated/stored but the
 * email is held back (no backfill; tomorrow's brief will simply be the next notification).
 */
class GenerateDailyBriefsAction
{
    private const MAX_TRENDS = 5;

    private const MAX_IDEAS = 5;

    public function __construct(
        private readonly DiscoverTrendsAction $discoverTrends,
        private readonly RecommendPostsAction $recommendPosts,
    ) {}

    /**
     * @return int the number of clients a (new) brief was generated for
     */
    public function __invoke(?Carbon $date = null): int
    {
        $date = ($date ?? Carbon::now())->startOfDay();
        $generated = 0;

        $clients = Client::query()
            ->where('status', ClientStatus::Active)
            // Step 15.3: no AI spend (or emails) for a suspended organization.
            ->whereHas('organization', fn ($q) => $q->whereNull('suspended_at'))
            ->with(['organization', 'campaigns' => fn ($q) => $q->where('status', CampaignStatus::Active)])
            ->get();

        foreach ($clients as $client) {
            // Idempotent: a re-run of the command for the same day never duplicates a brief.
            if (DailyBrief::query()->where('client_id', $client->id)->whereDate('brief_date', $date)->exists()) {
                continue;
            }

            Log::info('Generating daily AI brief', ['client_id' => $client->id, 'org_id' => $client->org_id]);

            $trends = $this->discoverTrends->__invoke($client, []);
            $ideas = $this->recommendPosts->__invoke($client);

            $brief = DB::transaction(function () use ($client, $date, $trends, $ideas) {
                return DailyBrief::create([
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'brief_date' => $date,
                    'trends' => array_slice($trends->jsonSerialize(), 0, self::MAX_TRENDS),
                    'ideas' => array_slice($ideas->jsonSerialize(), 0, self::MAX_IDEAS),
                ]);
            });

            $this->notify($client, $brief);

            $generated++;
        }

        return $generated;
    }

    /**
     * Email the client's organization Owners/Strategists, unless we're currently inside the
     * configured quiet hours window — the brief itself is never held back, only the email.
     */
    private function notify(Client $client, DailyBrief $brief): void
    {
        if ($this->isQuietHours(Carbon::now())) {
            Log::info('Daily AI brief email held back (quiet hours)', ['client_id' => $client->id, 'brief_id' => $brief->id]);

            return;
        }

        /** @var Organization|null $organization */
        $organization = $client->organization;

        if (! $organization) {
            return;
        }

        $recipients = $organization->users()
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new DailyBriefReadyMail($client, $brief));
        }

        $brief->forceFill(['notified_at' => Carbon::now()])->save();

        Log::info('Daily AI brief emailed', [
            'client_id' => $client->id,
            'brief_id' => $brief->id,
            'recipient_count' => $recipients->count(),
        ]);
    }

    private function isQuietHours(Carbon $now): bool
    {
        $start = (int) config('ai.daily_briefs.quiet_hours_start', 21);
        $end = (int) config('ai.daily_briefs.quiet_hours_end', 7);
        $hour = (int) $now->format('G');

        if ($start === $end) {
            return false;
        }

        // Window wraps midnight (e.g. 21 -> 7): quiet whenever hour >= start OR hour < end.
        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }
}
