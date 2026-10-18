<?php

namespace Tests\Feature\Publishing;

use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Publishing\PollPendingPublishStatusesAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishPostTargetJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Steps I-1.2 and I-1.3 — stale publish jobs no-op, scheduled posts can be moved safely, and the
 * per-target fan-out ends in `published` / `partially_published` / `failed` without ever leaving a
 * post stuck in `publishing` or posting the same attempt twice.
 */
class PublishPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-18 12:00:00');

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    // --- Step I-1.2: stale jobs and rescheduling -------------------------------------------

    public function test_unscheduling_and_rescheduling_leaves_exactly_one_effective_publish(): void
    {
        Queue::fake();
        [$post] = $this->postWithTargets(PostStatus::Approved, ['2026-10-20 09:00:00']);
        $transition = app(TransitionPostStatusAction::class);

        $transition($post, PostStatus::Scheduled, null);
        $firstId = $post->refresh()->publish_dispatch_id;
        $transition($post, PostStatus::Draft, null);
        $this->assertNull($post->refresh()->publish_dispatch_id);
        $transition($post, PostStatus::InternalReview, null);
        $transition($post, PostStatus::Approved, null);
        $transition($post, PostStatus::Scheduled, null);
        $secondId = $post->refresh()->publish_dispatch_id;

        $this->assertNotSame($firstId, $secondId);

        $jobs = Queue::pushed(PublishPostJob::class);
        $this->assertCount(2, $jobs);

        // Both queued jobs fire; only the one for the current schedule fans out.
        foreach ($jobs as $job) {
            $this->app->call([$job, 'handle']);
        }

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(1, $post->activityLogs()->where('to_status', PostStatus::Publishing)->count());
        Queue::assertPushed(PublishPostTargetJob::class, 1);
    }

    public function test_dragging_a_scheduled_post_redispatches_and_the_stale_job_no_ops(): void
    {
        Queue::fake();
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        [$post] = $this->postWithTargets(PostStatus::Approved, ['2026-10-20 09:00:00']);
        app(TransitionPostStatusAction::class)($post, PostStatus::Scheduled, null);
        $staleId = $post->refresh()->publish_dispatch_id;

        $this->actingAs($owner)
            ->post(route('posts.reschedule', $post), ['days' => 2])
            ->assertRedirect();

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertNotSame($staleId, $post->publish_dispatch_id);
        $this->assertSame('2026-10-22 09:00:00', $post->targets()->sole()->scheduled_at_utc?->format('Y-m-d H:i:s'));

        Queue::assertPushed(PublishPostJob::class, 2);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->dispatchId === $post->publish_dispatch_id
            && $job->delay?->format('Y-m-d H:i:s') === '2026-10-22 09:00:00');

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->app->call([new PublishPostJob($post->id, $staleId), 'handle']);

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
    }

    public function test_a_post_moved_earlier_publishes_at_the_new_time(): void
    {
        Queue::fake();
        [$post] = $this->postWithTargets(PostStatus::Approved, ['2026-10-25 09:00:00']);
        app(TransitionPostStatusAction::class)($post, PostStatus::Scheduled, null);

        app(ReschedulePostAction::class)($post, -5);

        $post->refresh();
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->dispatchId === $post->publish_dispatch_id
            && $job->delay?->format('Y-m-d H:i:s') === '2026-10-20 09:00:00');
    }

    // --- Step I-1.3: fan-out, partial publishes, retries ------------------------------------

    public function test_one_target_failing_while_another_succeeds_ends_partially_published(): void
    {
        Notification::fake();
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        [$post, $accounts] = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-18 12:00:00', '2026-10-18 12:00:00']);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->twice()->andReturnUsing(
            fn (Post $p, Collection $targets) => collect([
                $targets->first()->id === $accounts[0]->id
                    ? new TargetResult(accountId: $accounts[0]->id, ok: true, externalPostId: 'ext-ok')
                    : new TargetResult(accountId: $accounts[1]->id, ok: false, error: 'Token expired'),
            ]),
        );
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->runPublishJob($post);

        // One target accepted (awaiting confirmation), one failed: still publishing.
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        $accepted = $post->targets()->where('social_account_id', $accounts[0]->id)->sole();
        app(SyncPublishStatusAction::class)($accepted, true);

        $post->refresh();
        $this->assertSame(PostStatus::PartiallyPublished, $post->status);
        $this->assertStringContainsString('Token expired', (string) $post->activityLogs()->latest('id')->first()->note);
        Notification::assertSentTo($owner, PostPublishFailed::class);
    }

    public function test_retrying_only_resends_the_failed_targets(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        [$post, $accounts] = $this->postWithTargets(PostStatus::PartiallyPublished, ['2026-10-18 10:00:00', '2026-10-18 10:00:00']);
        $post->targets()->where('social_account_id', $accounts[0]->id)->update(['status' => PostTargetStatus::Published, 'external_post_id' => 'live-1']);
        $post->targets()->where('social_account_id', $accounts[1]->id)->update(['status' => PostTargetStatus::Failed, 'error' => 'Token expired']);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')
            ->once()
            ->withArgs(fn (Post $p, Collection $targets) => $targets->pluck('id')->all() === [$accounts[1]->id])
            ->andReturn(collect([new TargetResult(accountId: $accounts[1]->id, ok: true, externalPostId: 'ext-retry')]));
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->actingAs($owner)
            ->post(route('posts.retry-failed-targets', $post))
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame('live-1', $post->targets()->where('social_account_id', $accounts[0]->id)->sole()->external_post_id);

        $retried = $post->targets()->where('social_account_id', $accounts[1]->id)->sole();
        $this->assertSame('ext-retry', $retried->external_post_id);
        $this->assertNull($retried->error);

        app(SyncPublishStatusAction::class)($retried, true);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_retry_is_forbidden_for_viewers_and_for_posts_that_did_not_fail(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        [$failed] = $this->postWithTargets(PostStatus::Failed, ['2026-10-18 10:00:00']);
        [$published] = $this->postWithTargets(PostStatus::Published, ['2026-10-18 10:00:00']);

        $this->actingAs($viewer)->post(route('posts.retry-failed-targets', $failed))->assertForbidden();
        $this->actingAs($owner)->post(route('posts.retry-failed-targets', $published))->assertForbidden();
    }

    public function test_the_manual_transition_cannot_be_used_to_retry(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        [$post] = $this->postWithTargets(PostStatus::Failed, ['2026-10-18 10:00:00']);

        $this->actingAs($owner)
            ->post(route('posts.transition', $post), ['to' => PostStatus::Publishing->value])
            ->assertForbidden();
    }

    public function test_running_the_same_publish_job_twice_publishes_once(): void
    {
        [$post] = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-18 12:00:00']);
        $this->app->instance(PublishAdapter::class, $this->adapterAccepting(times: 1));

        $this->runPublishJob($post);
        $this->runPublishJob($post);

        $this->assertSame(1, $post->activityLogs()->where('to_status', PostStatus::Publishing)->count());
    }

    public function test_a_retried_target_job_never_resends_an_attempt_that_already_reached_the_vendor(): void
    {
        [$post] = $this->postWithTargets(PostStatus::Publishing, ['2026-10-18 12:00:00']);
        $target = $post->targets()->sole();
        $target->update(['publish_attempt_id' => 'attempt-1', 'publish_requested_at' => now()]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->app->call([new PublishPostTargetJob($target->id, 'attempt-1'), 'handle']);

        $this->assertSame(PostTargetStatus::Failed, $target->refresh()->status);
        $this->assertStringContainsString('interrupted', (string) $target->error);
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
    }

    public function test_a_stale_target_job_does_nothing(): void
    {
        [$post] = $this->postWithTargets(PostStatus::Publishing, ['2026-10-18 12:00:00']);
        $target = $post->targets()->sole();
        $target->update(['publish_attempt_id' => 'attempt-2']);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->app->call([new PublishPostTargetJob($target->id, 'attempt-1'), 'handle']);

        $this->assertSame(PostTargetStatus::Pending, $target->refresh()->status);
        $this->assertNull($target->publish_requested_at);
    }

    public function test_the_publish_job_failed_handler_fails_the_post_instead_of_leaving_it_stuck(): void
    {
        Notification::fake();
        [$post] = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-18 12:00:00']);
        $post->update(['publish_dispatch_id' => 'dispatch-9']);

        (new PublishPostJob($post->id, 'dispatch-9'))->failed(new RuntimeException('Database went away'));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $post->targets()->sole()->status);
        $this->assertStringContainsString('Database went away', (string) $post->activityLogs()->latest('id')->first()->note);
    }

    public function test_the_target_job_failed_handler_fails_the_target_and_resolves_the_post(): void
    {
        [$post, $accounts] = $this->postWithTargets(PostStatus::Publishing, ['2026-10-18 12:00:00', '2026-10-18 12:00:00']);
        $post->targets()->where('social_account_id', $accounts[0]->id)->update(['status' => PostTargetStatus::Published]);
        $crashed = $post->targets()->where('social_account_id', $accounts[1]->id)->sole();
        $crashed->update(['publish_attempt_id' => 'attempt-1']);

        (new PublishPostTargetJob($crashed->id, 'attempt-1'))->failed(new RuntimeException('Worker timed out'));

        $this->assertSame(PostTargetStatus::Failed, $crashed->refresh()->status);
        $this->assertSame(PostStatus::PartiallyPublished, $post->refresh()->status);
    }

    public function test_publish_fans_out_one_batched_job_per_target(): void
    {
        Bus::fake();
        [$post] = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-18 12:00:00', '2026-10-18 12:00:00']);

        $this->runPublishJob($post);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof PublishPostTargetJob)
            && $batch->allowsFailures());
    }

    public function test_a_target_with_no_delivery_confirmation_times_out_and_fails(): void
    {
        config(['publishing.delivery_timeout_hours' => 24]);
        [$post] = $this->postWithTargets(PostStatus::Publishing, ['2026-10-17 09:00:00']);
        $target = $post->targets()->sole();
        $target->update(['external_post_id' => 'ext-1', 'publish_requested_at' => now()]);
        PostTarget::query()->whereKey($target->id)->update(['updated_at' => now()->subHours(25)]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('checkStatus');

        app(PollPendingPublishStatusesAction::class)($adapter, app(SyncPublishStatusAction::class));

        $target->refresh();
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('No delivery confirmation after 24 hours.', $target->error);
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
    }

    /**
     * @param  list<string>  $utcTimes
     * @return array{0: Post, 1: list<SocialAccount>}
     */
    private function postWithTargets(PostStatus $status, array $utcTimes): array
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => $status]);
        $accounts = [];

        foreach ($utcTimes as $utc) {
            $account = SocialAccount::factory()->for($this->client)->create([
                'org_id' => $this->org->id,
                'platform' => Platform::LinkedIn,
                'timezone' => 'UTC',
            ]);
            $post->targets()->create([
                'social_account_id' => $account->id,
                'scheduled_local_date' => substr($utc, 0, 10),
                'scheduled_local_time' => substr($utc, 11, 5),
                'scheduled_at_utc' => $utc,
            ]);
            $accounts[] = $account;
        }

        return [$post, $accounts];
    }

    private function adapterAccepting(int $times): PublishAdapter
    {
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('publish')->times($times)->andReturnUsing(
            fn (Post $p, Collection $targets) => $targets->map(
                fn (SocialAccount $account) => new TargetResult(accountId: $account->id, ok: true, externalPostId: 'ext-'.$account->id),
            ),
        );

        return $adapter;
    }

    private function runPublishJob(Post $post): void
    {
        $this->app->call([new PublishPostJob($post->id, $post->refresh()->publish_dispatch_id), 'handle']);
    }
}
