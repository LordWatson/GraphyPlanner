<?php

namespace App\Actions\Business;

use App\Actions\AI\SummarizeInsightsAction;
use App\Enums\Role;
use App\Mail\WeeklyInsightsDigestMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step B-8.2 — build and send one business workspace's weekly Insights digest to its Owners and
 * Managers. Reuses {@see GetBusinessInsightsAction} for the numbers and {@see SummarizeInsightsAction}
 * for the plain-language takeaways (so the email and the page always agree, and share the weekly
 * cache). A workspace with nothing published yet is skipped — an empty digest would only nag.
 *
 * Never throws for one workspace: the weekly command catches and logs, so one brand can't stop the
 * sweep.
 */
class SendWeeklyInsightsDigestAction
{
    public function __construct(
        private readonly GetBusinessInsightsAction $insights,
        private readonly SummarizeInsightsAction $summarize,
    ) {}

    public function __invoke(Organization $organization): bool
    {
        if (! $organization->wantsInsightsDigest()) {
            return false;
        }

        $brand = $organization->primaryBrand;

        if ($brand === null) {
            return false;
        }

        $timezone = $organization->default_timezone ?: 'UTC';
        $insights = ($this->insights)($brand, $timezone);

        // Nothing published yet — don't send an empty digest.
        if ((int) ($insights['publishedTotal'] ?? 0) === 0) {
            return false;
        }

        $summary = ($this->summarize)($brand, $insights, $timezone);
        $takeaways = array_map(fn (array $item): string => $item['text'], $summary['takeaways']);

        $engagement = is_array($insights['engagement'] ?? null) ? $insights['engagement'] : [];
        $headline = [
            'streak' => (int) ($insights['streak'] ?? 0),
            'published' => (int) ($insights['publishedTotal'] ?? 0),
            'reach' => $engagement['totals']['reach'] ?? null,
            'followers' => $engagement['followers']['current'] ?? null,
            'period_days' => (int) ($insights['windowDays'] ?? 90),
        ];

        $recipients = $this->recipients($organization);

        if ($recipients->isEmpty()) {
            return false;
        }

        $url = route('business.insights.index');

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(
                new WeeklyInsightsDigestMail($brand, $takeaways, $headline, $url),
            );
        }

        Log::info('Weekly insights digest sent', [
            'org_id' => $organization->id,
            'client_id' => $brand->id,
            'recipients' => $recipients->count(),
            'takeaways' => count($takeaways),
        ]);

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Organization $organization): Collection
    {
        return User::query()
            ->where('org_id', $organization->id)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->whereNotNull('email_verified_at')
            ->get();
    }
}
