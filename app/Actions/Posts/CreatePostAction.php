<?php

namespace App\Actions\Posts;

use App\Contracts\Entitlements;
use App\Enums\ApprovalMode;
use App\Enums\Feature;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Client;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Plans\PlanUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreatePostAction
{
    /**
     * Create a new post (with its per-account targets) for the given client.
     *
     * `$data['targets']` is an array of `{social_account_id, scheduled_local_date, scheduled_local_time}`.
     * Each target's `scheduled_at_utc` is computed independently from its own social account's
     * timezone, so two targets on accounts in different timezones never share a UTC instant unless
     * their local date/time happen to coincide once converted (spec §4.6).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PlanLimitReachedException when the org's plan caps posts a month.
     */
    public function __invoke(Client $client, array $data): Post
    {
        // Step CG-2.2 / B-9.1: enforce the plan's monthly post allowance (per calendar month in the
        // org's own timezone). Agency plans are unlimited, so this never fires for them.
        $this->ensureWithinMonthlyPostLimit($client);

        return DB::transaction(function () use ($client, $data) {
            $post = Post::create([
                ...array_diff_key($data, ['targets' => null]),
                // Step I-3.8 / M-2.9: the client's default unless the post chose one.
                'approval_mode' => $data['approval_mode'] ?? $client->default_approval_mode ?? ApprovalMode::ClientRequired,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
            ]);

            foreach ($data['targets'] ?? [] as $target) {
                $account = SocialAccount::query()->whereKey((int) $target['social_account_id'])->firstOrFail();

                $scheduledAtUtc = null;

                if (! empty($target['scheduled_local_date']) && ! empty($target['scheduled_local_time'])) {
                    $scheduledAtUtc = Carbon::parse(
                        $target['scheduled_local_date'].' '.$target['scheduled_local_time'],
                        $account->timezone,
                    )->utc();
                }

                $post->targets()->create([
                    'social_account_id' => $account->id,
                    'scheduled_local_date' => $target['scheduled_local_date'] ?? null,
                    'scheduled_local_time' => $target['scheduled_local_time'] ?? null,
                    'scheduled_at_utc' => $scheduledAtUtc,
                ]);
            }

            Log::info('Post created', [
                'client_id' => $client->id,
                'post_id' => $post->id,
                'target_count' => $post->targets()->count(),
            ]);

            return $post;
        });
    }

    private function ensureWithinMonthlyPostLimit(Client $client): void
    {
        $organization = $client->organization;

        if ($organization === null) {
            return;
        }

        $used = (new PlanUsage)->scheduledPostsThisMonth($organization);

        app(Entitlements::class)->ensure($organization, Feature::ScheduledPostsPerMonth, $used);
    }
}
