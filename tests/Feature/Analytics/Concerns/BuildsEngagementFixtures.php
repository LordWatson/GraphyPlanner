<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\SocialAccount;
use App\Models\SocialAccountMetric;
use Carbon\CarbonInterface;

/**
 * Step M-3.1 — hand-built engagement fixtures: published channels with readings, and daily
 * account metrics, written straight to the tables the sync fills.
 */
trait BuildsEngagementFixtures
{
    protected function engagementOrg(string $timezone = 'UTC'): Organization
    {
        return Organization::factory()->create(['default_timezone' => $timezone, 'default_currency' => 'GBP', 'upload_post_key' => 'up-key']);
    }

    protected function engagementClient(Organization $org, string $name = 'Client'): Client
    {
        return Client::factory()->for($org, 'organization')->create(['name' => $name]);
    }

    protected function engagementAccount(Client $client, Platform $platform = Platform::Instagram, string $timezone = 'UTC'): SocialAccount
    {
        return SocialAccount::factory()->for($client)->create([
            'org_id' => $client->org_id,
            'platform' => $platform,
            'timezone' => $timezone,
            'handle' => '@'.$platform->value.$client->id,
            'connection_status' => ConnectionStatus::Connected,
            'external_account_id' => 'profile-'.$client->id,
        ]);
    }

    /**
     * A published post with one channel per account, each published at `$at`.
     *
     * @param  list<SocialAccount>  $accounts
     */
    protected function publishedPost(Client $client, array $accounts, CarbonInterface $at, array $attributes = []): Post
    {
        $post = Post::factory()->for($client)->create([
            'org_id' => $client->org_id,
            'status' => PostStatus::Published,
            ...$attributes,
        ]);

        foreach ($accounts as $account) {
            $post->targets()->create([
                'social_account_id' => $account->id,
                'status' => PostTargetStatus::Published,
                'external_post_id' => 'job-'.$post->id.'-'.$account->id,
                'published_at' => $at,
            ]);
        }

        return $post;
    }

    /**
     * Store a reading for a post's channel on `$account` and point the channel at it.
     *
     * @param  array<string, int|null>  $metrics
     */
    protected function reading(Post $post, SocialAccount $account, array $metrics): PostTargetMetric
    {
        /** @var PostTarget $target */
        $target = $post->targets()->where('social_account_id', $account->id)->firstOrFail();
        $engagement = array_filter([$metrics['likes'] ?? null, $metrics['comments'] ?? null, $metrics['shares'] ?? null, $metrics['saves'] ?? null], fn ($value) => $value !== null);

        $metric = PostTargetMetric::query()->create([
            'org_id' => $post->org_id,
            'post_target_id' => $target->id,
            'source' => 'test',
            'fetched_at' => now(),
            'engagement' => $engagement === [] ? null : array_sum($engagement),
            ...$metrics,
        ]);

        $target->forceFill(['latest_metrics_id' => $metric->id, 'metrics_synced_at' => now()])->save();

        return $metric;
    }

    protected function followersOn(SocialAccount $account, string $date, int $followers, ?int $reach = null): void
    {
        SocialAccountMetric::query()->create([
            'org_id' => $account->org_id,
            'social_account_id' => $account->id,
            'metric_date' => $date,
            'followers' => $followers,
            'reach' => $reach,
            'fetched_at' => now(),
        ]);
    }
}
