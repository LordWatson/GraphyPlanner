<?php

namespace Tests\Feature\Publishing;

use App\Actions\Clients\ArchiveClientAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Publishing\PollPendingPublishStatusesAction;
use App\Contracts\PublishAdapter;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Jobs\SendScheduledPostToVendorJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Publishing\CancelResult;
use App\Support\Publishing\TargetResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A post is sent to the vendor as soon as it's scheduled (`SendScheduledPostToVendorJob`), so
 * Upload-Post holds each channel with its own `scheduled_date` while the post stays `scheduled`
 * here. Taking it off the schedule, moving, deleting it or archiving its client cancels the held
 * channels first, and `PublishPostJob` at the post's time never sends them a second time.
 */
class ScheduledPostHandOffTest extends TestCase
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

    public function test_scheduling_sends_the_post_to_the_vendor_straight_away(): void
    {
        Queue::fake();
        $post = $this->postWithTargets(PostStatus::Approved, ['2026-10-20 09:00:00']);

        app(TransitionPostStatusAction::class)($post, PostStatus::Scheduled, null);

        $dispatchId = $post->refresh()->publish_dispatch_id;
        Queue::assertPushed(SendScheduledPostToVendorJob::class, fn (SendScheduledPostToVendorJob $job) => $job->dispatchId === $dispatchId
            && $job->delay === null);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->dispatchId === $dispatchId
            && $job->delay?->format('Y-m-d H:i:s') === '2026-10-20 09:00:00');
    }

    public function test_the_hand_off_sends_later_channels_and_keeps_the_post_scheduled(): void
    {
        $post = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-20 09:00:00', '2026-10-18 12:02:00']);
        [$later, $soon] = $post->targets()->orderBy('id')->get()->all();

        $adapter = $this->mockAdapter(function (MockInterface $adapter) use ($later) {
            $adapter->shouldReceive('publish')->once()->andReturnUsing(function (Post $p, Collection $accounts) use ($later) {
                $this->assertSame([$later->social_account_id], $accounts->pluck('id')->all());

                return $accounts->map(fn (SocialAccount $account) => new TargetResult(accountId: $account->id, ok: true, externalPostId: 'job-1'));
            });
        });

        $this->app->call([new SendScheduledPostToVendorJob($post->id, 'dispatch-1'), 'handle']);

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
        $this->assertSame('job-1', $later->refresh()->external_post_id);
        $this->assertSame(PostTargetStatus::Pending, $later->status);
        $this->assertTrue($later->isVendorHeld());
        $this->assertNull($soon->refresh()->publish_attempt_id, 'A channel due within minutes is left for PublishPostJob.');
        $this->assertTrue($post->isHeldByVendor());
        $adapter->shouldHaveReceived('publish')->once();
    }

    public function test_a_stale_hand_off_does_nothing(): void
    {
        $post = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-20 09:00:00']);
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldNotReceive('publish'));

        $this->app->call([new SendScheduledPostToVendorJob($post->id, 'an-old-schedule'), 'handle']);

        $this->assertNull($post->targets()->sole()->publish_attempt_id);
    }

    public function test_the_publish_job_at_the_posts_time_does_not_resend_held_channels(): void
    {
        $post = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-18 12:00:00', '2026-10-18 12:00:00']);
        [$held, $unsent] = $post->targets()->orderBy('id')->get()->all();
        $held->update(['external_post_id' => 'job-1', 'publish_attempt_id' => 'attempt-1', 'publish_requested_at' => now()->subDay()]);

        $this->mockAdapter(function (MockInterface $adapter) use ($unsent) {
            $adapter->shouldReceive('publish')->once()->andReturnUsing(function (Post $p, Collection $accounts) use ($unsent) {
                $this->assertSame([$unsent->social_account_id], $accounts->pluck('id')->all());

                return $accounts->map(fn (SocialAccount $account) => new TargetResult(accountId: $account->id, ok: true, externalPostId: 'job-2'));
            });
        });

        $this->app->call([new PublishPostJob($post->id, 'dispatch-1'), 'handle']);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $held->refresh();
        $this->assertSame('job-1', $held->external_post_id);
        $this->assertSame('attempt-1', $held->publish_attempt_id);
        $this->assertSame('job-2', $unsent->refresh()->external_post_id);
    }

    public function test_unscheduling_a_held_post_cancels_it_at_the_vendor(): void
    {
        $post = $this->heldPost();
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('cancel')->once()->andReturn(CancelResult::cancelled()));

        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, null);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $target = $post->targets()->sole();
        $this->assertNull($target->external_post_id);
        $this->assertNull($target->publish_attempt_id);
        $this->assertNull($target->publish_requested_at);
    }

    public function test_a_held_post_stays_scheduled_when_the_vendor_says_its_too_late(): void
    {
        $post = $this->heldPost();
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('cancel')->once()
            ->andReturn(CancelResult::failed('scheduled_post_in_progress', tooLate: true)));

        try {
            app(TransitionPostStatusAction::class)($post, PostStatus::Draft, null);
            $this->fail('Expected the unschedule to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to', $e->errors());
        }

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
        $this->assertSame('dispatch-1', $post->publish_dispatch_id);
        $this->assertSame('job-1', $post->targets()->sole()->external_post_id);
    }

    public function test_moving_a_held_post_cancels_it_and_sends_it_again_for_the_new_time(): void
    {
        Queue::fake();
        $post = $this->heldPost();
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('cancel')->once()->andReturn(CancelResult::cancelled()));

        app(ReschedulePostAction::class)($post, 2);

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertNotSame('dispatch-1', $post->publish_dispatch_id);
        $this->assertNull($post->targets()->sole()->external_post_id);
        Queue::assertPushed(SendScheduledPostToVendorJob::class, fn (SendScheduledPostToVendorJob $job) => $job->dispatchId === $post->publish_dispatch_id);
    }

    public function test_deleting_a_held_post_cancels_it_at_the_vendor_first(): void
    {
        $post = $this->heldPost();
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('cancel')->once()->andReturn(CancelResult::cancelled()));

        app(DeletePostAction::class)($post, $this->owner());

        $this->assertSoftDeleted($post);
    }

    public function test_archiving_a_client_releases_its_held_posts_even_when_the_vendor_refuses_one(): void
    {
        $released = $this->heldPost();
        $refused = $this->heldPost();
        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('cancel')->twice()->andReturnUsing(
            fn (PostTarget $target) => $target->post_id === $released->id
                ? CancelResult::cancelled()
                : CancelResult::failed('scheduled_post_in_progress', tooLate: true),
        ));

        app(ArchiveClientAction::class)($this->client, $this->owner());

        $this->assertSoftDeleted($this->client);
        $this->assertNull($released->targets()->sole()->external_post_id);
        $this->assertSame('job-1', $refused->targets()->sole()->external_post_id);
    }

    public function test_the_poller_leaves_held_channels_alone_until_they_are_due(): void
    {
        $post = $this->postWithTargets(PostStatus::Publishing, ['2026-10-20 09:00:00', '2026-10-18 10:00:00']);
        [$held, $due] = $post->targets()->orderBy('id')->get()->all();

        // Both were handed over three days ago — past the 24h timeout if it counted from then.
        foreach ([$held, $due] as $i => $target) {
            $target->update(['external_post_id' => 'job-'.$i, 'publish_attempt_id' => 'attempt-'.$i, 'publish_requested_at' => now()->subDays(3)]);
        }
        DB::table('post_targets')->update(['updated_at' => now()->subDays(3)]);

        $this->mockAdapter(fn (MockInterface $adapter) => $adapter->shouldReceive('checkStatus')->once()
            ->withArgs(fn (SocialAccount $account, string $externalId) => $externalId === 'job-1')
            ->andReturnNull());

        $this->app->call(app(PollPendingPublishStatusesAction::class));

        $this->assertSame(PostTargetStatus::Pending, $held->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $due->refresh()->status, 'The timeout counts from the scheduled time.');
    }

    /**
     * A `scheduled` post on schedule `dispatch-1`, one channel per UTC time.
     *
     * @param  list<string>  $utcTimes
     */
    private function postWithTargets(PostStatus $status, array $utcTimes): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => $status,
            'publish_dispatch_id' => 'dispatch-1',
        ]);

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
        }

        return $post->refresh();
    }

    /**
     * A `scheduled` post whose one channel the vendor is holding until 20 October.
     */
    private function heldPost(): Post
    {
        $post = $this->postWithTargets(PostStatus::Scheduled, ['2026-10-20 09:00:00']);
        $post->targets()->update(['external_post_id' => 'job-1', 'publish_attempt_id' => 'attempt-1', 'publish_requested_at' => now()]);

        return $post->refresh();
    }

    private function mockAdapter(callable $expectations): MockInterface
    {
        $adapter = Mockery::mock(PublishAdapter::class);
        $expectations($adapter);
        $this->app->instance(PublishAdapter::class, $adapter);

        return $adapter;
    }

    private function owner(): User
    {
        return User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }
}
