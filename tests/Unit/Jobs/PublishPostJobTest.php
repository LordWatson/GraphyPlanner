<?php

namespace Tests\Unit\Jobs;

use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class PublishPostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_every_target_and_keeps_the_post_publishing_pending_vendor_confirmation_when_all_targets_are_accepted(): void
    {
        [$post, $account] = $this->scheduledPost();
        $target = $post->targets()->firstOrFail();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')
            ->once()
            ->withArgs(fn (Post $publishedPost, Collection $targets) => $publishedPost->id === $post->id && $targets->pluck('id')->all() === [$account->id])
            ->andReturn(collect([
                new TargetResult(accountId: $account->id, ok: true, externalPostId: 'ext-123'),
            ]));
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->runJob($post);

        $post->refresh();
        $target->refresh();

        // The vendor only accepted the request here — it may still be queued/scheduled for
        // delivery — so the post/target only move to `published` once `SyncPublishStatusAction`
        // (webhook or poll fallback) confirms actual delivery.
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(PostTargetStatus::Pending, $target->status);
        $this->assertSame('ext-123', $target->external_post_id);
        $this->assertNull($target->error);
        $this->assertNotNull($target->publish_attempt_id);
        $this->assertNotNull($target->publish_requested_at);
    }

    public function test_it_marks_the_post_and_target_failed_when_a_target_fails(): void
    {
        [$post, $account] = $this->scheduledPost();
        $target = $post->targets()->firstOrFail();

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->once()->andReturn(collect([
            new TargetResult(accountId: $account->id, ok: false, error: 'Vendor rejected the request'),
        ]));
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->runJob($post);

        $post->refresh();
        $target->refresh();

        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('Vendor rejected the request', $target->error);

        // The vendor error must be recorded on the activity log entry, not only on the target,
        // so it's visible in one place alongside every other status change.
        $log = $post->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame(PostStatus::Failed, $log->to_status);
        $this->assertStringContainsString('Vendor rejected the request', (string) $log->note);
    }

    public function test_it_skips_a_post_that_is_no_longer_scheduled(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->runJob($post);

        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }

    public function test_it_is_unique_per_post_and_dispatch_id(): void
    {
        $this->assertSame('7:abc', (new PublishPostJob(7, 'abc'))->uniqueId());
        $this->assertSame('7:none', (new PublishPostJob(7))->uniqueId());
        $this->assertSame(3, (new PublishPostJob(7))->tries);
        $this->assertSame([30, 120, 600], (new PublishPostJob(7))->backoff());
    }

    /**
     * @return array{0: Post, 1: SocialAccount}
     */
    private function scheduledPost(): array
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Scheduled,
            'publish_dispatch_id' => 'dispatch-1',
        ]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_at_utc' => now(),
        ]);

        return [$post, $account];
    }

    private function runJob(Post $post): void
    {
        $this->app->call([new PublishPostJob($post->id, $post->publish_dispatch_id), 'handle']);
    }
}
