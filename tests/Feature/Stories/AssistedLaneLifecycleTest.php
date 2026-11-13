<?php

namespace Tests\Feature\Stories;

use App\Actions\Clients\ArchiveClientAction;
use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\RescheduleTargetAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Posts\UpdatePostAction;
use App\Actions\Publishing\RetryFailedPostTargetsAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\ProcessStoryHandoffsAction;
use App\Actions\Stories\ShowStoryHandoffAction;
use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Jobs\PublishPostJob;
use App\Jobs\SendScheduledPostToVendorJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Notifications\StoryPublishHandoffReady;
use App\Services\Publishing\VendorScheduleService;
use App\Support\Publishing\CancelResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-2.5 — every existing way to change a post behaves sensibly around an assisted Story:
 * retry, unschedule, reschedule, edit, archive and delete, before and after its handoff opens.
 */
class AssistedLaneLifecycleTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
        Notification::fake();

        $this->owner = $this->member(Role::Owner);
    }

    /**
     * A `publishing` Story-only post, due now, with its handoff open and assigned to the owner.
     */
    private function publishingStory(): Post
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $this->refusingAdapter();

        return $this->startPublishing($post);
    }

    /**
     * Mark a feed channel as already accepted by the vendor and held for its (future) time.
     */
    private function holdAtVendor(PostTarget $feed): PostTarget
    {
        $feed->update([
            'external_post_id' => 'job-feed',
            'publish_attempt_id' => 'attempt-feed',
            'publish_requested_at' => now(),
        ]);

        return $feed;
    }

    /**
     * Let the handoff's window pass and run the sweep, so the Story is marked as missed.
     */
    private function miss(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        app(ProcessStoryHandoffsAction::class)();
    }

    // --- Retry failed channels ---------------------------------------------------------------

    public function test_retrying_a_missed_story_opens_a_fresh_handoff_and_the_next_sweep_notifies(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);
        $oldAttempt = $this->storyTarget($post)->publish_attempt_id;
        $this->miss();
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);
        Notification::assertNotSentTo($this->owner, StoryPublishHandoffReady::class);

        $this->travelTo('2026-11-20 12:30:00');
        app(RetryFailedPostTargetsAction::class)($post, $this->owner);

        $post->refresh();
        $story = $this->storyTarget($post);
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->error);
        $this->assertNotSame($oldAttempt, $story->publish_attempt_id);

        // The old one was already closed as missed, and stays that way; only the new one is open.
        $this->assertSame(StoryHandoffOutcome::Missed, $old->refresh()->outcome);
        $this->assertSame(2, StoryHandoff::query()->count());
        $new = StoryHandoff::query()->open()->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($story->publish_attempt_id, $new->publish_attempt_id);
        $this->assertNull($new->notified_at);
        // Due at the Story's own (past) time, with a full window from now to post it in.
        $this->assertSame('2026-11-20 10:00:00', $new->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 14:30:00', $new->expires_at->format('Y-m-d H:i:s'));

        $this->travelTo('2026-11-20 12:31:00');
        $counts = app(ProcessStoryHandoffsAction::class)();

        $this->assertSame(1, $counts['notified']);
        $this->assertSame(0, $counts['expired']);
        Notification::assertSentToTimes($this->owner, StoryPublishHandoffReady::class, 1);
        Notification::assertSentTo($this->owner, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->handoffId === $new->id);
    }

    // --- Before publish time (post `scheduled`) ----------------------------------------------

    public function test_unscheduling_a_scheduled_mixed_post_recalls_only_the_vendor_held_channel(): void
    {
        $post = $this->storyPost(PostStatus::Scheduled, '2026-11-22 10:00:00');
        $feed = $this->holdAtVendor($this->addFeedChannel($post, '2026-11-22 10:00:00'));
        $this->assertSame(0, StoryHandoff::query()->count(), 'No handoff before the post starts publishing.');

        $cancelled = [];
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $adapter->shouldReceive('cancel')->once()->andReturnUsing(function (PostTarget $target) use (&$cancelled) {
            $cancelled[] = $target->id;

            return CancelResult::cancelled();
        });
        $this->app->instance(PublishAdapter::class, $adapter);

        app(TransitionPostStatusAction::class)($post->refresh(), PostStatus::Approved, $this->owner);

        $this->assertSame([$feed->id], $cancelled, 'Only the vendor-held channel is cancelled at the vendor.');
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $this->assertNull($post->publish_dispatch_id);
        $this->assertNull($feed->refresh()->external_post_id);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->publish_attempt_id);
        $this->assertTrue($story->handoff_required);
        $this->assertSame(0, StoryHandoff::query()->count());
    }

    public function test_moving_a_scheduled_mixed_post_recalls_the_feed_channel_and_simply_moves_the_story(): void
    {
        Queue::fake();
        $post = $this->storyPost(PostStatus::Scheduled, '2026-11-22 10:00:00');
        $feed = $this->holdAtVendor($this->addFeedChannel($post, '2026-11-22 10:00:00'));

        $cancelled = [];
        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $adapter->shouldReceive('cancel')->once()->andReturnUsing(function (PostTarget $target) use (&$cancelled) {
            $cancelled[] = $target->id;

            return CancelResult::cancelled();
        });
        $this->app->instance(PublishAdapter::class, $adapter);

        app(ReschedulePostAction::class)($post->refresh(), 1, $this->owner);

        $this->assertSame([$feed->id], $cancelled);
        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertNotSame('dispatch-1', $post->publish_dispatch_id);

        $story = $this->storyTarget($post);
        $this->assertSame('2026-11-23', $story->scheduled_local_date->toDateString());
        $this->assertSame('2026-11-23 10:00:00', $story->scheduled_at_utc->format('Y-m-d H:i:s'));
        $this->assertNull($story->publish_attempt_id);
        $this->assertSame('2026-11-23', $feed->refresh()->scheduled_local_date->toDateString());
        $this->assertSame(0, StoryHandoff::query()->count());

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->dispatchId === $post->publish_dispatch_id);
        Queue::assertPushed(SendScheduledPostToVendorJob::class, fn (SendScheduledPostToVendorJob $job) => $job->dispatchId === $post->publish_dispatch_id);
    }

    public function test_moving_just_the_story_channel_of_a_scheduled_post_makes_no_vendor_call(): void
    {
        Queue::fake();
        Http::fake();
        $post = $this->storyPost(PostStatus::Scheduled, '2026-11-22 10:00:00');
        // A strict mock: any `cancel` or `publish` on it fails the test.
        $this->app->instance(PublishAdapter::class, Mockery::mock(PublishAdapter::class));

        app(RescheduleTargetAction::class)($this->storyTarget($post), '2026-11-24', '15:30', $this->owner);

        $story = $this->storyTarget($post);
        $this->assertSame('2026-11-24', $story->scheduled_local_date->toDateString());
        $this->assertSame('2026-11-24 15:30:00', $story->scheduled_at_utc->format('Y-m-d H:i:s'));
        $this->assertTrue($story->handoff_required);
        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
        $this->assertNotSame('dispatch-1', $post->publish_dispatch_id);
        $this->assertSame(0, StoryHandoff::query()->count());
        Http::assertNothingSent();
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->dispatchId === $post->publish_dispatch_id
            && $job->delay instanceof \DateTimeInterface
            && $job->delay->format('Y-m-d H:i:s') === '2026-11-24 15:30:00');
    }

    // --- After publish time (post `publishing`, handoff open) --------------------------------

    public function test_unscheduling_a_publishing_story_cancels_its_handoff_and_resets_the_channel(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);

        $this->assertTrue($post->canBeRecalled());
        $this->assertTrue($this->owner->can('transition', [$post, PostStatus::Approved]));

        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner, 'Hold on.');

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertNull($post->publish_dispatch_id);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $handoff->refresh()->outcome);
        $this->assertNotNull($handoff->completed_at);
        $this->assertSame(0, StoryHandoff::query()->open()->count());

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->publish_attempt_id);
        $this->assertNull($story->error);
        $this->assertTrue($story->handoff_required, 'Still a hand-posted Story the next time it is scheduled.');
    }

    public function test_moving_a_publishing_story_to_a_later_time_reopens_its_handoff_with_the_new_due_time(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);

        app(ReschedulePostAction::class)($post, 1, $this->owner);

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $old->refresh()->outcome);

        $new = StoryHandoff::query()->open()->sole();
        $story = $this->storyTarget($post);
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($old->publish_attempt_id, $new->publish_attempt_id);
        $this->assertSame($story->publish_attempt_id, $new->publish_attempt_id);
        $this->assertSame('2026-11-21 10:00:00', $new->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-21 12:00:00', $new->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($new->notified_at);

        // Nobody is told until the new time.
        $this->assertSame(0, app(ProcessStoryHandoffsAction::class)()['notified']);
        Notification::assertNothingSent();
    }

    public function test_nothing_changes_when_a_vendor_held_channel_on_the_same_post_refuses_to_cancel(): void
    {
        $this->org->update(['upload_post_key' => 'org-key']);
        Http::fake(['*/uploadposts/schedule/*' => Http::response(['success' => false, 'error' => 'scheduled_post_in_progress'], 409)]);

        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->holdAtVendor($this->addFeedChannel($post, '2026-11-22 10:00:00'));
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);
        $attempt = $this->storyTarget($post)->publish_attempt_id;
        $this->assertTrue($post->canBeRecalled());

        try {
            app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner);
            $this->fail('Expected the unschedule to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('can’t be changed now', $e->errors()['to'][0]);
        }

        Http::assertSentCount(1);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame('job-feed', $feed->refresh()->external_post_id);
        $this->assertTrue($handoff->refresh()->isOpen(), 'The handoff was closed even though the recall was refused.');
        $this->assertSame($attempt, $this->storyTarget($post)->publish_attempt_id);
        $this->assertSame(1, StoryHandoff::query()->count());
    }

    // --- Late confirmation --------------------------------------------------------------------

    public function test_a_missed_story_only_post_can_still_be_confirmed_as_posted(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $this->miss();
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        // From the link, by somebody who is not signed in.
        app(CompleteStoryHandoffAction::class)($handoff->refresh(), null);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertNull($story->error);
        $this->assertNotNull($story->published_at);
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->refresh()->outcome);
    }

    public function test_confirming_a_missed_story_late_finishes_a_partially_published_post(): void
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);
        app(SyncPublishStatusAction::class)($feed->refresh(), true);
        $this->miss();
        $this->assertSame(PostStatus::PartiallyPublished, $post->refresh()->status);

        app(CompleteStoryHandoffAction::class)($handoff->refresh(), $this->owner, StoryHandoff::VIA_STAFF);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);
        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertSame($this->owner->id, $handoff->completed_by_user_id);
        $this->assertSame(StoryHandoff::VIA_STAFF, $handoff->completed_via);
    }

    public function test_confirming_a_missed_story_late_leaves_a_failed_feed_channel_failed(): void
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);
        app(SyncPublishStatusAction::class)($feed->refresh(), false, 'The platform rejected the media.');
        $this->miss();
        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        app(CompleteStoryHandoffAction::class)($handoff->refresh(), $this->owner);

        $this->assertSame(PostStatus::PartiallyPublished, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);
        $this->assertSame(PostTargetStatus::Failed, $feed->refresh()->status);
        $this->assertSame('The platform rejected the media.', $feed->error);
    }

    public function test_a_late_confirmation_is_refused_once_the_channel_has_been_retried(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);
        $this->miss();
        app(RetryFailedPostTargetsAction::class)($post, $this->owner);

        try {
            app(CompleteStoryHandoffAction::class)($old->refresh(), $this->owner);
            $this->fail('A missed handoff must not be confirmable after its channel was retried.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no longer active', $e->errors()['handoff'][0]);
        }

        $this->assertSame(StoryHandoffOutcome::Missed, $old->refresh()->outcome);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->status);
        $this->assertTrue(StoryHandoff::query()->open()->sole()->isCurrent());
    }

    // --- Editing while a handoff is open -----------------------------------------------------

    public function test_editing_the_extras_keeps_the_handoff_open_and_the_page_shows_the_edit(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $story = $this->storyTarget($post);

        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $story->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '10:00',
            'story_extras' => ['version' => 1, 'link' => ['url' => 'https://example.com/new-offer', 'text' => 'Book now'], 'hashtag' => 'winter'],
        ]]], $this->owner);

        $open = StoryHandoff::query()->open()->sole();
        $this->assertSame($handoff->id, $open->id);
        $this->assertSame(1, StoryHandoff::query()->count());
        $this->assertSame($story->publish_attempt_id, $this->storyTarget($post)->publish_attempt_id);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        $show = app(ShowStoryHandoffAction::class);
        $token = $this->issueToken($open);
        $payload = $show->payload($show->resolve($token), $token);

        $this->assertSame('ready', $payload['handoff']['state']);
        $this->assertSame('https://example.com/new-offer', $payload['handoff']['link']);
        $values = array_column($payload['handoff']['extras'], 'value', 'key');
        $this->assertSame('https://example.com/new-offer', $values['link']);
        $this->assertSame('Book now', $values['link_text']);
        $this->assertSame('#winter', $values['hashtag']);
        $this->assertNotContains('https://example.com/offer', $values);
    }

    public function test_removing_every_extra_sends_the_story_to_the_vendor_and_cancels_the_handoff(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $story = $this->storyTarget($post);
        $sent = [];
        $this->acceptingAdapter($sent);

        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $story->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '10:00',
            'story_extras' => null,
        ]]], $this->owner);

        $this->assertSame([$story->social_account_id], $sent);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $handoff->refresh()->outcome);
        $this->assertSame(0, StoryHandoff::query()->open()->count());

        $story->refresh();
        $this->assertFalse($story->handoff_required);
        $this->assertSame('job-'.$story->social_account_id, $story->external_post_id);
        $this->assertNotSame($handoff->publish_attempt_id, $story->publish_attempt_id);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_changing_the_time_of_a_story_with_an_open_handoff_reopens_it_for_the_new_time(): void
    {
        $post = $this->publishingStory();
        $old = $this->openHandoff($post);
        $oldToken = $old->plainToken();
        $story = $this->storyTarget($post);

        // Saved from the editor with only the time moved: 10:00 → 14:00.
        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $story->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '14:00',
            'story_extras' => $this->linkExtras(),
        ]]], $this->owner);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $old->refresh()->outcome);
        $this->assertNotNull($old->completed_at);

        $new = StoryHandoff::query()->open()->sole();
        $story->refresh();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($old->publish_attempt_id, $story->publish_attempt_id);
        $this->assertSame($story->publish_attempt_id, $new->publish_attempt_id);
        $this->assertSame('2026-11-20 14:00:00', $new->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 16:00:00', $new->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($new->notified_at);
        $this->assertTrue($story->handoff_required);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->external_post_id);

        // The old time's link is gone; the new one opens.
        $show = app(ShowStoryHandoffAction::class);
        $this->assertNotSame($oldToken, $new->plainToken());
        $this->assertSame($new->id, $show->resolve($new->plainToken())->id);

        try {
            $show->resolve($oldToken);
            $this->fail('The link for the old time still opens.');
        } catch (NotFoundHttpException) {
            // Cancelled with the old attempt.
        }

        // Nobody is told at the old time, and it does not fail as missed when the old window ends.
        $this->assertSame(0, app(ProcessStoryHandoffsAction::class)()['notified']);
        $this->travelTo('2026-11-20 12:00:00');
        $this->assertSame(0, app(ProcessStoryHandoffsAction::class)()['expired']);
        Notification::assertNothingSent();

        $this->travelTo('2026-11-20 14:00:00');
        $this->assertSame(1, app(ProcessStoryHandoffsAction::class)()['notified']);
        Notification::assertSentTo($this->owner, StoryPublishHandoffReady::class, fn (StoryPublishHandoffReady $notification) => $notification->handoffId === $new->id);
    }

    public function test_saving_without_moving_the_time_does_not_reopen_the_handoff(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $story = $this->storyTarget($post);

        app(UpdatePostAction::class)($post, ['master_caption' => 'A new caption.', 'targets' => [[
            'social_account_id' => $story->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '10:00',
            'story_extras' => $this->linkExtras(),
        ]]], $this->owner);

        $this->assertSame($handoff->id, StoryHandoff::query()->open()->sole()->id);
        $this->assertSame(1, StoryHandoff::query()->count());
        $this->assertSame($story->publish_attempt_id, $this->storyTarget($post)->publish_attempt_id);
    }

    public function test_removing_the_awaiting_story_from_a_post_whose_other_channel_failed_resolves_the_post(): void
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);
        $token = $handoff->plainToken();
        app(SyncPublishStatusAction::class)($feed->refresh(), false, 'The platform rejected the media.');
        // Still waiting on the Story.
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        // The Story channel is taken off the post; the failed feed channel is all that is left.
        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $feed->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '10:00',
        ]]], $this->owner);

        $post->refresh();
        $this->assertSame([$feed->id], $post->targets()->pluck('id')->all());
        // The regression: nothing was left to report back, so the post sat in `publishing`.
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $feed->refresh()->status);
        $this->assertSame('The platform rejected the media.', $feed->error);

        // And the removed Story's link is dead, with nothing left open for the sweep to chase.
        $counts = app(ProcessStoryHandoffsAction::class)();
        $this->assertSame(0, $counts['notified'] + $counts['expired'] + $counts['failed']);
        $this->assertSame(0, StoryHandoff::query()->open()->count());

        try {
            app(ShowStoryHandoffAction::class)->resolve($token);
            $this->fail('The link for a removed Story channel still opens.');
        } catch (NotFoundHttpException) {
            // Gone with its channel.
        }
    }

    public function test_removing_the_awaiting_story_from_a_post_whose_other_channel_published_resolves_the_post(): void
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);
        app(SyncPublishStatusAction::class)($feed->refresh(), true);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);

        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $feed->social_account_id,
            'scheduled_local_date' => '2026-11-20',
            'scheduled_local_time' => '10:00',
        ]]], $this->owner);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    // --- A "Posted" that lands during a recall ---------------------------------------------------

    public function test_a_recall_leaves_alone_a_story_that_was_confirmed_as_posted_a_moment_before(): void
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $this->owner->id]);
        $feed = $this->holdAtVendor($this->addFeedChannel($post, '2026-11-22 10:00:00'));
        $post = $this->startPublishing($post);
        $handoff = $this->openHandoff($post);
        $attempt = $this->storyTarget($post)->publish_attempt_id;

        // The recall has read the post: the Story is, as far as it knows, still waiting.
        $stale = Post::query()->with('targets.socialAccount.organization')->findOrFail($post->id);
        $this->assertTrue($stale->canBeRecalled());
        $this->assertTrue($stale->targets->firstWhere('format', 'story')?->isAwaitingHandoff());

        // …and the poster taps "I've posted it" before the recall takes its locks.
        app(CompleteStoryHandoffAction::class)(StoryHandoff::query()->findOrFail($handoff->id), $this->owner);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $adapter->shouldReceive('cancel')->once()->andReturn(CancelResult::cancelled());
        $this->app->instance(PublishAdapter::class, $adapter);

        $recalled = app(VendorScheduleService::class)->recall($stale);

        // Only the vendor-held channel was taken back.
        $this->assertSame([$feed->id], $recalled);
        $this->assertNull($feed->refresh()->external_post_id);

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertSame($attempt, $story->publish_attempt_id);
        $this->assertNotNull($story->published_at);

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertSame($this->owner->id, $handoff->completed_by_user_id);
        $this->assertSame(0, StoryHandoff::query()->where('outcome', StoryHandoffOutcome::Cancelled)->count());
    }

    public function test_recalling_a_story_only_post_that_was_just_confirmed_changes_nothing(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $attempt = $this->storyTarget($post)->publish_attempt_id;
        $stale = Post::query()->with('targets.socialAccount.organization')->findOrFail($post->id);

        app(CompleteStoryHandoffAction::class)(StoryHandoff::query()->findOrFail($handoff->id), null);

        $this->assertSame([], app(VendorScheduleService::class)->recall($stale));

        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertSame($attempt, $story->publish_attempt_id);
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->refresh()->outcome);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_recalling_a_publishing_story_logs_which_handoffs_were_cancelled(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $log = Log::spy();

        $this->assertSame([$handoff->post_target_id], app(VendorScheduleService::class)->recall($post));

        $this->assertSame(StoryHandoffOutcome::Cancelled, $handoff->refresh()->outcome);
        $log->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Story handoffs cancelled'
                && $context['org_id'] === $this->org->id
                && $context['post_ids'] === [$post->id]
                && $context['story_handoff_ids'] === [$handoff->id]
                && $context['count'] === 1
                && $context['reason'] === 'post recalled')
            ->once();
    }

    // --- The kill switch goes off with a Story mid-handoff -------------------------------------

    public function test_with_the_lane_switched_off_a_story_already_with_a_person_stays_by_hand_and_can_be_completed(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);
        $story = $this->storyTarget($post);
        $token = $handoff->plainToken();

        config(['publishing.story_handoff.enabled' => false]);
        Http::fake();
        // A strict mock: any `publish` or `cancel` on it fails the test.
        $this->app->instance(PublishAdapter::class, Mockery::mock(PublishAdapter::class));

        // Once with the extras in the payload (the editor's save), once without (an API save).
        foreach ([['story_extras' => $this->linkExtras()], ['story_extras' => null], []] as $extra) {
            app(UpdatePostAction::class)($post->refresh(), ['master_caption' => 'Edited with the lane off.', 'targets' => [[
                'social_account_id' => $story->social_account_id,
                'scheduled_local_date' => '2026-11-20',
                'scheduled_local_time' => '10:00',
                ...$extra,
            ]]], $this->owner);

            $story->refresh();
            $this->assertTrue($story->handoff_required, 'The Story was moved off the hand-posted lane mid-handoff.');
            $this->assertSame('https://example.com/offer', $story->story_extras['link']['url'] ?? null);
            $this->assertSame($handoff->publish_attempt_id, $story->publish_attempt_id);
            $this->assertNull($story->external_post_id);
            $this->assertSame(PostTargetStatus::Pending, $story->status);
            $this->assertSame($handoff->id, StoryHandoff::query()->open()->sole()->id);
            $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        }

        Http::assertNothingSent();

        // It still finishes by hand, from the same link.
        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($token)->id);
        app(CompleteStoryHandoffAction::class)($handoff->refresh(), $this->owner);

        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->refresh()->outcome);
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_with_the_lane_switched_off_a_flagged_story_that_is_not_out_yet_is_written_back_to_the_auto_lane(): void
    {
        $post = $this->storyPost(PostStatus::Draft, '2026-11-22 10:00:00');
        $story = $this->storyTarget($post);
        $this->assertTrue($story->handoff_required);
        $this->assertFalse($story->isAwaitingHandoff());

        config(['publishing.story_handoff.enabled' => false]);

        app(UpdatePostAction::class)($post, ['targets' => [[
            'social_account_id' => $story->social_account_id,
            'scheduled_local_date' => '2026-11-22',
            'scheduled_local_time' => '10:00',
        ]]], $this->owner);

        $story->refresh();
        $this->assertFalse($story->handoff_required);
        // What was stored is kept, for when the lane comes back.
        $this->assertSame('https://example.com/offer', $story->story_extras['link']['url'] ?? null);
    }

    // --- Archive and delete -------------------------------------------------------------------

    public function test_no_notification_is_sent_for_an_archived_clients_due_handoff(): void
    {
        $post = $this->publishingStory();
        $handoff = $this->openHandoff($post);

        app(ArchiveClientAction::class)($this->client, $this->owner);
        $counts = app(ProcessStoryHandoffsAction::class)();

        $this->assertSame(['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0], $counts);
        $this->assertNull($handoff->refresh()->notified_at);
        $this->assertTrue($handoff->isOpen());
        Notification::assertNothingSent();
    }

    public function test_a_scheduled_assisted_post_can_be_deleted_without_a_vendor_call(): void
    {
        Http::fake();
        $post = $this->storyPost(PostStatus::Scheduled, '2026-11-22 10:00:00');
        // A strict mock: any `cancel` or `publish` on it fails the test.
        $this->app->instance(PublishAdapter::class, Mockery::mock(PublishAdapter::class));
        $this->assertTrue($this->owner->can('delete', $post));

        app(DeletePostAction::class)($post, $this->owner);

        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertSame(0, StoryHandoff::query()->count());
        Http::assertNothingSent();
    }
}
