<?php

namespace Tests\Feature\Stories;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\OpenStoryHandoffAction;
use App\Actions\Stories\ReportStoryHandoffProblemAction;
use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Enums\StoryHandoffRecipient;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishPostTargetJob;
use App\Jobs\SendScheduledPostToVendorJob;
use App\Models\ClientContact;
use App\Models\StoryHandoff;
use App\Notifications\PostPublishFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Steps S-2.1, S-2.2 and S-2.4 — a Story with extras never reaches the vendor. It gets a handoff
 * when its post starts publishing, and a person's "Posted" publishes it through the same outcome
 * seam the vendor's confirmation uses.
 */
class AssistedLanePipelineTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
    }

    public function test_scheduling_a_mixed_post_sends_only_the_feed_channel_to_the_vendor(): void
    {
        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00');
        $feed = $this->addFeedChannel($post, '2026-11-22 10:00:00');
        $sent = [];
        $this->acceptingAdapter($sent);
        // The delayed publish job stays queued; the hand-off to the vendor runs now.
        Queue::fake([PublishPostJob::class]);

        app(TransitionPostStatusAction::class)($post, PostStatus::Scheduled, null);

        $this->assertSame([$feed->social_account_id], $sent);
        $this->assertNotNull($feed->refresh()->external_post_id);

        $story = $this->storyTarget($post);
        $this->assertNull($story->external_post_id);
        $this->assertNull($story->publish_attempt_id);
        $this->assertSame(0, StoryHandoff::query()->count(), 'No handoff until the post starts publishing.');
    }

    public function test_scheduling_a_story_only_assisted_post_makes_no_vendor_request(): void
    {
        Http::fake();
        $post = $this->storyPost(PostStatus::Approved, '2026-11-22 10:00:00');
        $this->org->update(['upload_post_key' => 'org-key']);
        Queue::fake([PublishPostJob::class]);

        app(TransitionPostStatusAction::class)($post, PostStatus::Scheduled, null);

        Http::assertNothingSent();
        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
    }

    public function test_a_plain_story_still_goes_to_the_vendor(): void
    {
        $post = $this->storyPost(PostStatus::Scheduled, '2026-11-22 10:00:00', extras: []);
        $story = $this->storyTarget($post);
        $this->assertFalse($story->handoff_required);
        $sent = [];
        $this->acceptingAdapter($sent);

        $this->app->call([new SendScheduledPostToVendorJob($post->id, 'dispatch-1'), 'handle']);

        $this->assertSame([$story->social_account_id], $sent);
        $this->assertSame(0, StoryHandoff::query()->count());
    }

    public function test_the_target_job_refuses_to_send_an_assisted_channel(): void
    {
        $post = $this->storyPost(PostStatus::Publishing);
        $story = $this->storyTarget($post);
        $story->update(['publish_attempt_id' => 'attempt-1']);
        $this->refusingAdapter();

        $this->app->call([new PublishPostTargetJob($story->id, 'attempt-1'), 'handle']);

        $story->refresh();
        $this->assertNull($story->publish_requested_at);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
    }

    public function test_publishing_opens_one_handoff_and_leaves_the_channel_pending(): void
    {
        $assignee = $this->member(Role::Designer);
        $post = $this->storyPost(postAttributes: ['assignee_id' => $assignee->id]);
        $this->refusingAdapter();

        $post = $this->startPublishing($post);

        $this->assertSame(PostStatus::Publishing, $post->status);
        $story = $this->storyTarget($post);
        $this->assertSame(PostTargetStatus::Pending, $story->status);
        $this->assertNull($story->external_post_id);

        $handoff = StoryHandoff::query()->sole();
        $this->assertTrue($handoff->isOpen());
        $this->assertSame($story->publish_attempt_id, $handoff->publish_attempt_id);
        $this->assertSame(StoryHandoffRecipient::Team, $handoff->recipient_type);
        $this->assertSame($assignee->id, $handoff->user_id);
        $this->assertSame('2026-11-20 10:00:00', $handoff->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 12:00:00', $handoff->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->org->id, $handoff->org_id);
        $this->assertSame($this->client->id, $handoff->client_id);
    }

    public function test_the_handoff_is_due_at_the_storys_own_time_not_the_posts(): void
    {
        $post = $this->storyPost(utc: '2026-11-20 11:00:00');
        $this->addFeedChannel($post, '2026-11-20 10:00:00');
        $this->acceptingAdapter();

        $this->startPublishing($post);

        $handoff = StoryHandoff::query()->sole();
        $this->assertSame('2026-11-20 11:00:00', $handoff->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 13:00:00', $handoff->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_opening_twice_for_one_attempt_yields_one_row(): void
    {
        $post = $this->startPublishing($this->storyPost());
        $story = $this->storyTarget($post);

        $first = app(OpenStoryHandoffAction::class)($story);
        $second = app(OpenStoryHandoffAction::class)($story);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, StoryHandoff::query()->count());
    }

    public function test_a_new_attempt_cancels_the_old_handoff_and_opens_a_new_one(): void
    {
        $post = $this->startPublishing($this->storyPost());
        $story = $this->storyTarget($post);
        $old = $this->openHandoff($post);

        $story->update(['publish_attempt_id' => (string) Str::uuid()]);
        $new = app(OpenStoryHandoffAction::class)($story->refresh());

        $this->assertNotNull($new);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(StoryHandoffOutcome::Cancelled, $old->refresh()->outcome);
        $this->assertTrue($new->isOpen());
    }

    public function test_the_poster_falls_back_from_assignee_to_creator_to_everyone(): void
    {
        $creator = $this->member(Role::Strategist);
        $viewer = $this->member(Role::Viewer);

        $byCreator = $this->startPublishing($this->storyPost(postAttributes: ['created_by' => $creator->id]));
        $this->assertSame($creator->id, $this->openHandoff($byCreator)->user_id);

        // A Viewer cannot act on a post, so being assigned one does not make them the poster.
        $nobody = $this->startPublishing($this->storyPost(postAttributes: ['assignee_id' => $viewer->id]));
        $handoff = $this->openHandoff($nobody);
        $this->assertSame(StoryHandoffRecipient::Team, $handoff->recipient_type);
        $this->assertNull($handoff->user_id);
    }

    public function test_a_client_recipient_is_resolved_from_the_channel_or_the_clients_default(): void
    {
        ClientContact::factory()->for($this->client)->create(['org_id' => $this->org->id, 'receives_review_emails' => true]);

        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client]);
        $byDefault = $this->startPublishing($this->storyPost());
        $this->assertSame(StoryHandoffRecipient::Client, $this->openHandoff($byDefault)->recipient_type);

        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Team]);
        $overridden = $this->storyPost();
        $this->storyTarget($overridden)->update(['handoff_recipient' => StoryHandoffRecipient::Client]);
        $this->assertSame(StoryHandoffRecipient::Client, $this->openHandoff($this->startPublishing($overridden))->recipient_type);
    }

    public function test_confirming_publishes_a_story_only_post(): void
    {
        $member = $this->member();
        $post = $this->startPublishing($this->storyPost());
        $handoff = $this->openHandoff($post);

        app(CompleteStoryHandoffAction::class)($handoff, $member);

        $post->refresh();
        $story = $this->storyTarget($post);
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(PostTargetStatus::Published, $story->status);
        $this->assertNotNull($story->published_at);
        $this->assertNull($story->post_url, 'Alima cannot see Instagram, so there is no post URL.');

        $handoff->refresh();
        $this->assertSame(StoryHandoffOutcome::Posted, $handoff->outcome);
        $this->assertSame($member->id, $handoff->completed_by_user_id);
        $this->assertSame(StoryHandoff::VIA_LINK, $handoff->completed_via);
        $this->assertNotNull($handoff->completed_at);

        $note = $post->activityLogs()->where('user_id', $member->id)->whereNotNull('note')->sole();
        $this->assertStringContainsString('posted by hand', (string) $note->note);
        $this->assertSame('posted_by_hand', $story->load(['post', 'openStoryHandoff', 'postedStoryHandoff'])->handoffState());
    }

    public function test_confirming_a_mixed_post_waits_for_the_vendor_channel(): void
    {
        $post = $this->storyPost();
        $feed = $this->addFeedChannel($post);
        $this->acceptingAdapter();
        $post = $this->startPublishing($post);

        app(CompleteStoryHandoffAction::class)($this->openHandoff($post), null);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status, 'The feed channel is still waiting on the vendor.');
        $this->assertSame(PostTargetStatus::Published, $this->storyTarget($post)->status);

        app(SyncPublishStatusAction::class)($feed->refresh(), true);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_a_second_confirm_is_refused_with_a_friendly_error(): void
    {
        $post = $this->startPublishing($this->storyPost());
        $handoff = $this->openHandoff($post);
        app(CompleteStoryHandoffAction::class)($handoff, null);

        try {
            app(CompleteStoryHandoffAction::class)($handoff->refresh(), null);
            $this->fail('A second confirm must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no longer active', $e->errors()['handoff'][0]);
        }

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_confirming_a_stale_attempt_is_refused(): void
    {
        $post = $this->startPublishing($this->storyPost());
        $handoff = $this->openHandoff($post);
        $this->storyTarget($post)->update(['publish_attempt_id' => (string) Str::uuid()]);

        $this->expectException(ValidationException::class);

        app(CompleteStoryHandoffAction::class)($handoff, null);
    }

    public function test_a_problem_report_fails_the_channel_with_the_note_and_tells_the_team(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $post = $this->startPublishing($this->storyPost());

        app(ReportStoryHandoffProblemAction::class)($this->openHandoff($post), 'The video would not upload.', null);

        $post->refresh();
        $story = $this->storyTarget($post);
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $story->status);
        $this->assertStringContainsString('The video would not upload.', (string) $story->error);
        $this->assertSame(PublishFailureReason::StoryProblemReported, PublishFailureReason::classify($story->error));

        $handoff = StoryHandoff::query()->sole();
        $this->assertSame(StoryHandoffOutcome::Problem, $handoff->outcome);
        $this->assertSame('The video would not upload.', $handoff->problem_note);
        Notification::assertSentTo($owner, PostPublishFailed::class);
    }

    public function test_the_vendor_is_never_asked_about_an_assisted_story(): void
    {
        $post = $this->startPublishing($this->storyPost());

        $adapter = \Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('checkStatus');
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        // Well past the generic 24-hour delivery timeout, but inside a long custom window.
        config(['publishing.story_handoff.window_minutes' => 60 * 48]);
        $this->openHandoff($post)->update(['expires_at' => now()->addHours(48)]);
        $this->travel(30)->hours();

        $this->artisan('publishing:poll-pending-statuses')->assertSuccessful();

        $this->assertSame(PostTargetStatus::Pending, $this->storyTarget($post)->refresh()->status);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }
}
