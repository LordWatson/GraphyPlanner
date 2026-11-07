<?php

namespace App\Actions\Benchmarks;

use App\Contracts\Entitlements;
use App\Contracts\InsightsSummarizer;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\TrackedProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step GTM-4.2 — the benchmark payload for one brand: its own Instagram figures against the
 * profiles it tracks, over `benchmarks.window_days`.
 *
 * **A gap is rendered as a gap.** Every figure is nullable all the way to the page; a profile with
 * no readings in the window comes back with `has_data => false` and nulls, and a window with no
 * readings at all comes back with an empty list rather than a row of zeroes. Nothing here
 * substitutes 0 for "we don't know" — a benchmark that does that makes a competitor look worse
 * than they are, which is the one thing it must never do.
 *
 * It deliberately does **not** go into `GetOrganizationAnalyticsSummaryAction`: that summary feeds
 * four surfaces, and benchmarks belong only on a client's own page and in that client's report.
 */
class GetClientBenchmarksAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly InsightsSummarizer $summarizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $brand, bool $withTakeaway = false): array
    {
        $organization = $brand->organization;
        $entitled = $organization !== null
            && $this->entitlements->allows($organization, Feature::CompetitorBenchmarks);

        $windowDays = max(1, (int) config('benchmarks.window_days', 30));
        $since = now()->subDays($windowDays)->toDateString();

        $payload = [
            'entitled' => $entitled,
            'window_days' => $windowDays,
            'us' => $this->us($brand, $since),
            'profiles' => $entitled ? $this->profiles($brand, $since) : [],
            'takeaway' => null,
        ];

        if ($entitled && $withTakeaway && $payload['profiles'] !== []) {
            $payload['takeaway'] = $this->takeaway($brand, $payload);
        }

        return $payload;
    }

    /**
     * The brand's own Instagram figures, on the same three measures as a tracked profile so the
     * rows are actually comparable. Instagram only, because that is the one platform a competitor
     * can be read on at all (see `benchmarks.md`) — comparing our TikTok against nothing would be
     * a column of blanks.
     *
     * @return array<string, mixed>
     */
    private function us(Client $brand, string $since): array
    {
        $accountIds = SocialAccount::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->where('platform', Platform::Instagram)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return ['name' => $brand->name, 'connected' => false, 'followers' => null, 'post_count' => null, 'avg_engagement' => null];
        }

        // The latest follower count we actually captured in the window, not a sum: followers is a
        // level, not a flow.
        $followers = DB::table('social_account_metrics')
            ->whereIn('social_account_id', $accountIds)
            ->whereNotNull('followers')
            ->where('metric_date', '>=', $since)
            ->orderByDesc('metric_date')
            ->value('followers');

        $targets = DB::table('post_targets')
            ->whereIn('social_account_id', $accountIds)
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $since);

        $postCount = (clone $targets)->count();

        $engagement = DB::table('post_target_metrics')
            ->whereIn('post_target_id', (clone $targets)->select('id'))
            ->whereNotNull('engagement')
            ->avg('engagement');

        return [
            'name' => $brand->name,
            'connected' => true,
            'followers' => $followers === null ? null : (int) $followers,
            'post_count' => $postCount,
            // Null rather than 0 when nothing was measured: "no readings" isn't "no engagement".
            'avg_engagement' => $engagement === null ? null : (int) round((float) $engagement),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profiles(Client $brand, string $since): array
    {
        $profiles = TrackedProfile::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->orderBy('id')
            ->get();

        if ($profiles->isEmpty()) {
            return [];
        }

        // One grouped read for every profile's window: the newest row for the current figures and
        // the oldest for the follower change, so the panel is two queries however long the list.
        $latest = $this->readings($profiles->pluck('id')->all(), $since, 'desc');
        $earliest = $this->readings($profiles->pluck('id')->all(), $since, 'asc');

        return array_values($profiles->map(function (TrackedProfile $profile) use ($latest, $earliest) {
            $now = $latest[$profile->id] ?? null;
            $then = $earliest[$profile->id] ?? null;

            return [
                'id' => $profile->id,
                'name' => $profile->displayName(),
                'handle' => '@'.$profile->handle,
                'platform' => $profile->platform->value,
                'platform_label' => $profile->platform->label(),
                'has_data' => $now !== null,
                'followers' => $now?->followers === null ? null : (int) $now->followers,
                'followers_change' => $now?->followers === null || $then?->followers === null
                    ? null
                    : (int) $now->followers - (int) $then->followers,
                'post_count' => $now?->post_count === null ? null : (int) $now->post_count,
                'avg_engagement' => $now?->avg_engagement === null ? null : (int) $now->avg_engagement,
                'captured_on' => $now === null ? null : substr((string) $now->captured_on, 0, 10),
                'last_error' => $now === null ? $profile->last_error : null,
            ];
        })->all());
    }

    /**
     * The newest (or oldest) reading in the window per profile, keyed by profile id.
     *
     * @param  list<int>  $profileIds
     * @return array<int, object>
     */
    private function readings(array $profileIds, string $since, string $direction): array
    {
        $rows = DB::table('tracked_profile_metrics')
            ->whereIn('tracked_profile_id', $profileIds)
            ->where('captured_on', '>=', $since)
            ->orderBy('captured_on', $direction)
            ->get(['tracked_profile_id', 'captured_on', 'followers', 'post_count', 'avg_engagement']);

        $keyed = [];

        foreach ($rows as $row) {
            // Ordered, so the first row seen per profile is the one this direction wants.
            $keyed[(int) $row->tracked_profile_id] ??= $row;
        }

        return $keyed;
    }

    /**
     * One plain-English sentence through the existing `InsightsSummarizer` seam. Never throws and
     * never blocks the panel: a disabled or failed provider simply means no takeaway.
     *
     * @param  array<string, mixed>  $payload
     */
    private function takeaway(Client $brand, array $payload): ?string
    {
        $facts = [
            'window_days' => $payload['window_days'],
            'you' => array_filter($payload['us'], fn ($value) => $value !== null),
            'tracked' => array_values(array_map(fn (array $row) => array_filter([
                'name' => $row['name'],
                'followers' => $row['followers'],
                'posts' => $row['post_count'],
                'avg_engagement' => $row['avg_engagement'],
            ], fn ($value) => $value !== null), array_filter(
                $payload['profiles'],
                fn (array $row) => $row['has_data'],
            ))),
        ];

        if ($facts['tracked'] === []) {
            return null;
        }

        try {
            return $this->summarizer->summarizeInsights($brand, ['benchmarks' => $facts])[0] ?? null;
        } catch (Throwable $e) {
            Log::info('Benchmark takeaway unavailable', ['client_id' => $brand->id, 'exception' => $e::class]);

            return null;
        }
    }
}
