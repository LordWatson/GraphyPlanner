<?php

namespace Tests\Feature\Stories;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Actions\Stories\ExpireStoryHandoffAction;
use App\Actions\Stories\NotifyStoryPosterAction;
use App\Actions\Stories\ProcessStoryHandoffsAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Notifications\PostPublishFailed;
use App\Notifications\StoryPublishHandoffReady;
use App\Support\Scheduling\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-2.3 (and S-6.2) — the per-minute sweep (`stories:process-handoffs`): a handoff left alone
 * goes notify → nudge → missed, exactly once each; a paused or stale one is never chased; and a
 * post that asked for it goes out plain instead of failing.
 */
class ProcessStoryHandoffsTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $poster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 09:55:00');
        $this->setUpStoryHandoffs();
        Notification::fake();

        $this->poster = $this->member(Role::Designer);
    }

    /**
     * A `publishing` post whose only channel is an assisted Story due at 10:00, assigned to the poster.
     *
     * @param  array<string, mixed>|null  $extras
     */
    private function publishingStory(?array $extras = null): Post
    {
        $post = $this->storyPost(extras: $extras, postAttributes: ['assignee_id' => $this->poster->id]);
        $this->refusingAdapter();

        return $this->startPublishing($post);
    }

    /**
     * @return array{notified: int, nudged: int, expired: int, cancelled: int, failed: int}
     */
    private function sweep(): array
    {
        return app(ProcessStoryHandoffsAction::class)();
    }

    public function test_nobody_is_notified_before_the_story_is_due(): void
    {
        $post = $this->publishingStory();

        $this->travelTo('2026-11-20 09:59:00');
        $counts = $this->sweep();

        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0], $counts);
        $this->assertNull($this->openHandoff($post)->notified_at);
        Notification::assertNothingSent();
    }

    public function test_the_poster_is_notified_exactly_once_at_the_due_time_across_two_runs(): void
    {
        $post = $this->publishingStory();

        $this->travelTo('2026-11-20 10:00:00');
        $this->assertSame(0, Artisan::call('stories:process-handoffs'));
        $this->travelTo('2026-11-20 10:01:00');
        Artisan::call('stories:process-handoffs');

        $handoff = $this->openHandoff($post);
        $this->assertSame('2026-11-20 10:00:00', $handoff->notified_at?->format('Y-m-d H:i:s'));
        $this->assertNull($handoff->nudged_at);
        Notification::assertSentToTimes($this->poster, StoryPublishHandoffReady::class, 1);
        Notification::assertSentTo($this->poster, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->handoffId === $handoff->id
            && $notification->late === false
            && $notification->nudge === false);
    }

    public function test_a_sweep_running_inside_the_grace_period_is_not_flagged_late(): void
    {
        $this->publishingStory();

        // `late_grace_minutes` is 10: exactly on the edge is still on time.
        $this->travelTo('2026-11-20 10:10:00');
        $this->assertSame(1, $this->sweep()['notified']);

        Notification::assertSentTo($this->poster, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->late === false);
    }

    public function test_a_sweep_running_past_the_grace_period_flags_the_notification_late(): void
    {
        $this->publishingStory();

        $this->travelTo('2026-11-20 10:11:00');
        $this->assertSame(1, $this->sweep()['notified']);

        Notification::assertSentTo($this->poster, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->late === true
            && $notification->nudge === false);
    }

    public function test_the_poster_is_nudged_once_after_the_nudge_delay(): void
    {
        $post = $this->publishingStory();

        $this->travelTo('2026-11-20 10:00:00');
        $this->sweep();

        $this->travelTo('2026-11-20 10:14:00');
        $this->assertSame(0, $this->sweep()['nudged'], 'Nudged before `nudge_after_minutes` had passed.');

        $this->travelTo('2026-11-20 10:15:00');
        $this->assertSame(1, $this->sweep()['nudged']);

        $this->travelTo('2026-11-20 10:40:00');
        $this->assertSame(0, $this->sweep()['nudged'], 'Nudged a second time.');

        $handoff = $this->openHandoff($post);
        $this->assertSame('2026-11-20 10:15:00', $handoff->nudged_at?->format('Y-m-d H:i:s'));
        Notification::assertSentToTimes($this->poster, StoryPublishHandoffReady::class, 2);
        Notification::assertSentTo($this->poster, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->nudge === true);
    }

    public function test_a_poster_who_already_shared_the_media_is_not_nudged(): void
    {
        $post = $this->publishingStory();

        $this->travelTo('2026-11-20 10:00:00');
        $this->sweep();
        $this->openHandoff($post)->update(['opened_at' => now(), 'shared_at' => now()]);

        $this->travelTo('2026-11-20 10:30:00');
        $this->assertSame(0, $this->sweep()['nudged']);

        $this->assertNull($this->openHandoff($post)->nudged_at);
        Notification::assertSentToTimes($this->poster, StoryPublishHandoffReady::class, 1);
    }

    public function test_expiry_fails_a_story_only_post_and_tells_the_team(): void
    {
        $owner = $this->member(Role::Owner);
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);

        $this->travelTo('2026-11-20 12:00:00');
        $counts = $this->sweep();

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(0, $counts['notified'], 'An expired handoff is failed, not announced.');

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Missed, $handoff->outcome);
        $this->assertSame('2026-11-20 12:00:00', $handoff->completed_at?->format('Y-m-d H:i:s'));

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertSame(ExpireStoryHandoffAction::MISSED_ERROR, $story->error);
        $this->assertSame(PublishFailureReason::StoryNotPosted, PublishFailureReason::classify($story->error));
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        Notification::assertSentTo($owner, PostPublishFailed::class);
        Notification::assertSentTo($this->poster, PostPublishFailed::class);
        Notification::assertNotSentTo($this->poster, StoryPublishHandoffReady::class);

        // And it is over: another run changes nothing.
        $this->travelTo('2026-11-20 12:01:00');
        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0], $this->sweep());
    }

    public function test_expiry_leaves_a_mixed_post_partially_published(): void
    {
        $owner = $this->member(Role::Owner);
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->poster->id]);
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        app(SyncPublishStatusAction::class)($feed->refresh(), true);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(1, $this->sweep()['expired']);

        $this->assertSame(PostStatus::PartiallyPublished, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $feed->refresh()->status);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertSame(PublishFailureReason::StoryNotPosted, PublishFailureReason::classify($story->error));
        Notification::assertSentTo($owner, PostPublishFailed::class);
    }

    public function test_an_archived_clients_handoff_is_skipped_and_resumes_when_it_is_restored(): void
    {
        $post = $this->publishingStory();
        $this->client->delete();

        $this->travelTo('2026-11-20 10:00:00');
        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0], $this->sweep());

        $handoff = $this->openHandoff($post);
        $this->assertNull($handoff->notified_at);
        Notification::assertNothingSent();

        $this->client->restore();
        $this->travelTo('2026-11-20 10:05:00');
        $this->assertSame(1, $this->sweep()['notified']);

        $this->assertNotNull($handoff->refresh()->notified_at);
        Notification::assertSentToTimes($this->poster, StoryPublishHandoffReady::class, 1);
    }

    public function test_a_suspended_organizations_handoff_is_skipped_and_resumes_when_the_hold_lifts(): void
    {
        $post = $this->publishingStory();
        $this->org->forceFill(['suspended_at' => now()])->save();

        $this->travelTo('2026-11-20 10:00:00');
        $this->assertSame(0, $this->sweep()['notified']);

        // Still held well into its window: nobody is told or nudged, and it stays open.
        $this->travelTo('2026-11-20 11:30:00');
        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0], $this->sweep());
        $this->assertTrue($this->openHandoff($post)->isOpen());
        $this->assertNull($this->openHandoff($post)->notified_at);
        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
        Notification::assertNothingSent();

        // The hold lifts inside the window: it carries on from where it was.
        $this->org->forceFill(['suspended_at' => null])->save();
        $this->assertSame(1, $this->sweep()['notified']);

        Notification::assertSentToTimes($this->poster, StoryPublishHandoffReady::class, 1);
    }

    public function test_a_suspended_organizations_handoff_expires_when_the_hold_outlasts_its_window(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $this->org->forceFill(['suspended_at' => now()])->save();

        $this->travelTo('2026-11-20 11:59:00');
        $this->assertSame(0, $this->sweep()['expired']);
        $this->assertTrue($handoff->refresh()->isOpen());

        // The window ends with the hold still on: the Story is failed, never left pending.
        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 1, 'cancelled' => 0, 'failed' => 0], $this->sweep());

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Missed, $handoff->outcome);
        $this->assertNull($handoff->notified_at);
        $this->assertNull($handoff->nudged_at);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertSame(PublishFailureReason::StoryNotPosted, PublishFailureReason::classify($story->error));
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
        Notification::assertNotSentTo($this->poster, StoryPublishHandoffReady::class);
    }

    public function test_an_archived_clients_handoff_expires_when_it_stays_archived_past_its_window(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $this->client->delete();

        $this->travelTo('2026-11-20 12:00:00');
        $counts = $this->sweep();

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(0, $counts['notified']);
        $this->assertSame(StoryHandoffOutcome::Missed, $handoff->refresh()->outcome);
        $this->assertSame(PostTargetStatus::Failed, $this->storyTarget($post)->status);
        Notification::assertNotSentTo($this->poster, StoryPublishHandoffReady::class);
    }

    public function test_one_handoff_that_throws_is_counted_and_logged_and_the_rest_still_run(): void
    {
        $first = $this->openHandoff($this->publishingStory());
        $second = $this->openHandoff($this->publishingStory());
        $this->assertLessThan($second->id, $first->id);

        $notified = [];
        $notify = Mockery::mock(NotifyStoryPosterAction::class);
        $notify->shouldReceive('__invoke')->twice()->andReturnUsing(function (StoryHandoff $handoff) use ($first, &$notified): void {
            if ($handoff->id === $first->id) {
                throw new RuntimeException('The mail server is down.');
            }

            $notified[] = $handoff->id;
        });
        $this->app->instance(NotifyStoryPosterAction::class, $notify);

        Exceptions::fake();
        $log = Log::spy();

        $this->travelTo('2026-11-20 10:00:00');
        $counts = $this->sweep();

        $this->assertSame(['notified' => 1, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 1], $counts);
        $this->assertSame([$second->id], $notified);
        $this->assertNotNull($second->refresh()->notified_at);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The mail server is down.');

        $log->shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Story handoff sweep: a handoff could not be processed'
                && $context['story_handoff_id'] === $first->id
                && $context['post_id'] === $first->post_id
                && $context['org_id'] === $this->org->id
                && $context['exception'] === RuntimeException::class);
    }

    public function test_a_throwing_expiry_does_not_stop_the_next_handoff_expiring(): void
    {
        $first = $this->openHandoff($this->publishingStory());
        $second = $this->openHandoff($this->publishingStory());

        $real = new ExpireStoryHandoffAction;
        $expire = Mockery::mock(ExpireStoryHandoffAction::class);
        $expire->shouldReceive('__invoke')->twice()->andReturnUsing(function (StoryHandoff $handoff) use ($first, $real): ?StoryHandoffOutcome {
            if ($handoff->id === $first->id) {
                throw new RuntimeException('Deadlock found.');
            }

            return $real($handoff);
        });
        $this->app->instance(ExpireStoryHandoffAction::class, $expire);
        Exceptions::fake();

        $this->travelTo('2026-11-20 12:00:00');
        $counts = $this->sweep();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['expired']);
        $this->assertTrue($first->refresh()->isOpen(), 'The one that threw is left for the next run.');
        $this->assertSame(StoryHandoffOutcome::Missed, $second->refresh()->outcome);
    }

    public function test_a_handoff_whose_attempt_was_replaced_is_cancelled_without_failing_the_channel(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $this->storyTarget($post)->update(['publish_attempt_id' => (string) Str::uuid()]);

        $this->travelTo('2026-11-20 12:00:00');
        $counts = $this->sweep();

        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(0, $counts['expired']);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $handoff->refresh()->outcome);
        $this->assertNotNull($handoff->completed_at);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->error);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_handoff_whose_post_left_publishing_is_cancelled_without_failing_the_channel(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        Post::query()->whereKey($post->id)->update(['status' => PostStatus::Approved]);

        $this->travelTo('2026-11-20 10:00:00');
        $counts = $this->sweep();

        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(0, $counts['notified']);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $handoff->refresh()->outcome);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->error);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_story_that_asked_to_go_out_plain_is_queued_for_the_vendor_at_expiry(): void
    {
        $post = $this->publishingStory([...$this->linkExtras(), 'if_missed' => 'publish_plain']);
        $story = $this->storyTarget($post);
        $handoff = $this->openHandoff($post);
        Queue::fake([PublishPostTargetJob::class]);

        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(1, $this->sweep()['expired']);

        $this->assertSame(StoryHandoffOutcome::PublishedPlain, $handoff->refresh()->outcome);
        $this->assertNotNull($handoff->completed_at);
        Queue::assertPushed(PublishPostTargetJob::class, 1);
        Queue::assertPushed(PublishPostTargetJob::class, fn (PublishPostTargetJob $job) => $job->plain === true
            && $job->targetId === $story->id
            && $job->attemptId === $story->publish_attempt_id);

        // Not failed: the channel is on its way to the vendor, and the stored lane is untouched.
        $story->refresh();
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->error);
        $this->assertTrue($story->handoff_required);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        Notification::assertNotSentTo($this->poster, PostPublishFailed::class);
    }

    public function test_a_story_that_asked_to_go_out_plain_reaches_the_vendor(): void
    {
        $post = $this->publishingStory([...$this->linkExtras(), 'if_missed' => 'publish_plain']);
        $story = $this->storyTarget($post);
        $sent = [];
        $this->acceptingAdapter($sent);

        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(1, $this->sweep()['expired']);

        $this->assertSame([$story->social_account_id], $sent);
        $story->refresh();
        $this->assertSame('job-'.$story->social_account_id, $story->external_post_id);
        $this->assertNotNull($story->publish_requested_at);
        $this->assertSame(PostTargetStatus::Pending, $story->status, 'Accepted by the vendor, waiting on its confirmation.');
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(StoryHandoffOutcome::PublishedPlain, StoryHandoff::query()->sole()->outcome);
    }

    public function test_the_sweep_is_registered_with_the_scheduler_heartbeat_every_minute(): void
    {
        $this->assertArrayHasKey('stories:process-handoffs', SchedulerHeartbeat::COMMANDS);
        $this->assertSame(1, SchedulerHeartbeat::COMMANDS['stories:process-handoffs']['interval_minutes']);
    }
}
