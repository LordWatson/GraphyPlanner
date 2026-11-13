<?php

namespace Tests\Feature\Stories;

use App\Actions\Posts\SendUpcomingPostReminderEmailsAction;
use App\Enums\NotificationCategory;
use App\Enums\PostStatus;
use App\Mail\UpcomingPostReminderMail;
use App\Models\Post;
use App\Models\User;
use App\Notifications\UpcomingPublishReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-3.4 — the morning heads-up: the daily "going out soon" digest says how many Stories are
 * to be posted by hand today, and when, in the email and in the WhatsApp summary. It rides the
 * existing sweep and its `reminder_sent_at` guard.
 */
class StoryMorningHeadsUpTest extends TestCase
{
    use BuildsStoryHandoffs;
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // 08:00 in London (GMT), when `posts:send-upcoming-reminders` runs.
        Carbon::setTestNow('2026-11-20 08:00:00');

        $this->setUpStoryHandoffs();
        $this->org->update(['default_timezone' => 'Europe/London']);
        // Opted into `publishing` on WhatsApp with a verified number, so the reminder has a channel.
        $this->member = $this->member();
        $this->member->forceFill([
            'phone' => '+447700900123',
            'phone_verified_at' => now(),
            'notification_preferences' => [NotificationCategory::Publishing->value => ['whatsapp' => true]],
        ])->save();

        Mail::fake();
        Notification::fake();
    }

    private function scheduledPost(): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Scheduled,
            'master_caption' => 'Autumn menu',
        ]);
    }

    private function sentMail(): UpcomingPostReminderMail
    {
        /** @var UpcomingPostReminderMail $mail */
        $mail = Mail::queued(UpcomingPostReminderMail::class)->sole();

        return $mail;
    }

    public function test_the_email_counts_and_times_the_stories_to_post_by_hand_today(): void
    {
        // Paris is an hour ahead of UTC in November: each time shows in its own channel's zone.
        $paris = $this->instagramAccount('@acmeparis', 'Europe/Paris');
        $post = $this->scheduledPost();
        $this->addStoryChannel($post, '2026-11-20 15:30:00', account: $paris);
        $this->addStoryChannel($post, '2026-11-20 10:00:00');
        // Tomorrow's Story and today's plain feed post are in the digest but not in the heads-up.
        $this->addStoryChannel($post, '2026-11-21 10:00:00');
        $this->addFeedChannel($post, '2026-11-20 12:00:00');

        $this->assertSame(4, app(SendUpcomingPostReminderEmailsAction::class)());

        $mail = $this->sentMail();
        $this->assertSame('2 stories to post by hand today (10:00, 16:30).', $mail->storyHeadsUp);

        $mail->assertSeeInHtml('2 stories to post by hand today (10:00, 16:30).');
        $mail->assertSeeInHtml('Instagram only allows links and stickers from its own app.');
        $mail->assertSeeInHtml("We'll remind whoever is posting when each one is due.", false);
        $this->assertSame(3, substr_count($mail->render(), 'posted by hand</span>'));
    }

    public function test_one_story_reads_in_the_singular(): void
    {
        $this->addStoryChannel($this->scheduledPost(), '2026-11-20 10:00:00');

        app(SendUpcomingPostReminderEmailsAction::class)();

        $this->assertSame('1 story to post by hand today (10:00).', $this->sentMail()->storyHeadsUp);
    }

    public function test_the_email_says_nothing_without_a_story_to_post_by_hand_today(): void
    {
        $post = $this->scheduledPost();
        // A plain Story auto-publishes; a Story with extras tomorrow is not today's job.
        $this->addStoryChannel($post, '2026-11-20 10:00:00', extras: []);
        $this->addStoryChannel($post, '2026-11-21 10:00:00');
        $this->addFeedChannel($post, '2026-11-20 12:00:00');

        app(SendUpcomingPostReminderEmailsAction::class)();

        $mail = $this->sentMail();
        $this->assertNull($mail->storyHeadsUp);
        $mail->assertDontSeeInHtml('to post by hand today');
        $mail->assertDontSeeInHtml('Instagram only allows links');
    }

    public function test_today_is_the_organizations_day(): void
    {
        // 08:00 UTC is 17:00 in Tokyo, so 16:00 UTC is already tomorrow (01:00) there.
        $this->org->update(['default_timezone' => 'Asia/Tokyo']);
        $post = $this->scheduledPost();
        $this->addStoryChannel($post, '2026-11-20 14:00:00');
        $this->addStoryChannel($post, '2026-11-20 16:00:00');

        app(SendUpcomingPostReminderEmailsAction::class)();

        $this->assertSame('1 story to post by hand today (14:00).', $this->sentMail()->storyHeadsUp);
    }

    public function test_the_lane_switched_off_means_no_heads_up(): void
    {
        $this->addStoryChannel($this->scheduledPost(), '2026-11-20 10:00:00');
        config(['publishing.story_handoff.enabled' => false]);

        app(SendUpcomingPostReminderEmailsAction::class)();

        $mail = $this->sentMail();
        $this->assertNull($mail->storyHeadsUp);
        $mail->assertDontSeeInHtml('posted by hand');
    }

    public function test_a_second_run_sends_nothing(): void
    {
        $this->addStoryChannel($this->scheduledPost(), '2026-11-20 10:00:00');

        $this->assertSame(1, app(SendUpcomingPostReminderEmailsAction::class)());
        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());

        Mail::assertQueuedCount(1);
        Notification::assertSentToTimes($this->member, UpcomingPublishReminder::class, 1);
    }

    public function test_a_story_reminded_days_ahead_still_gets_its_heads_up_on_the_day(): void
    {
        $this->addStoryChannel($this->scheduledPost(), '2026-11-22 10:00:00');

        // Day 1: inside the 3-day window, so it is reminded about and stamped. Not today's job yet.
        $this->assertSame(1, app(SendUpcomingPostReminderEmailsAction::class)());
        $this->assertNull($this->sentMail()->storyHeadsUp);

        // Its own day: nothing new to remind about, and the Story is still announced.
        Carbon::setTestNow('2026-11-22 08:00:00');
        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());

        Mail::assertQueuedCount(2);
        /** @var UpcomingPostReminderMail $mail */
        $mail = Mail::queued(UpcomingPostReminderMail::class)->last();
        $this->assertTrue($mail->storiesOnly);
        $this->assertSame('1 story to post by hand today', $mail->headline);
        $this->assertSame('1 story to post by hand today (10:00).', $mail->storyHeadsUp);
        $mail->assertHasSubject('1 story to post by hand today');
        $mail->assertSeeInHtml('1 story to post by hand today (10:00).');
        $mail->assertSeeInHtml('@acmecoffee');
        $mail->assertSeeInHtml('is posting by hand today');
        $mail->assertDontSeeInHtml('due to be published');
        $mail->assertDontSeeInHtml('one-time reminder');

        Notification::assertSentTo(
            $this->member,
            UpcomingPublishReminder::class,
            fn (UpcomingPublishReminder $reminder) => $reminder->toWhatsApp($this->member)->values['post_summary']
                === '1 story to post by hand today',
        );

        // A second run that day says nothing twice.
        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());
        Mail::assertQueuedCount(2);
        Notification::assertSentToTimes($this->member, UpcomingPublishReminder::class, 2);
    }

    public function test_todays_stories_lead_the_digest_when_there_is_something_new_too(): void
    {
        $post = $this->scheduledPost();
        // Already reminded about on an earlier morning; due today.
        $this->addStoryChannel($post, '2026-11-20 10:00:00')->forceFill(['reminder_sent_at' => now()->subDays(2)])->save();
        $this->addFeedChannel($post, '2026-11-21 12:00:00');

        $this->assertSame(1, app(SendUpcomingPostReminderEmailsAction::class)());

        $mail = $this->sentMail();
        $this->assertFalse($mail->storiesOnly);
        $this->assertSame('A post is scheduled to go out soon', $mail->headline);
        $this->assertSame('1 story to post by hand today (10:00).', $mail->storyHeadsUp);
        $this->assertCount(1, $mail->occurrences);

        Notification::assertSentTo(
            $this->member,
            UpcomingPublishReminder::class,
            fn (UpcomingPublishReminder $reminder) => $reminder->toWhatsApp($this->member)->values['post_summary']
                === '1 post, 1 story to post by hand today',
        );
    }

    public function test_nothing_new_and_no_stories_today_sends_no_mail(): void
    {
        $post = $this->scheduledPost();
        $this->addFeedChannel($post, '2026-11-20 12:00:00')->forceFill(['reminder_sent_at' => now()->subDay()])->save();
        // Tomorrow's Story, already reminded about: not today's.
        $this->addStoryChannel($post, '2026-11-21 10:00:00')->forceFill(['reminder_sent_at' => now()->subDay()])->save();

        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_a_story_that_is_not_waiting_any_more_is_not_announced(): void
    {
        $draft = $this->scheduledPost();
        $draft->update(['status' => PostStatus::Draft]);
        $this->addStoryChannel($draft, '2026-11-20 10:00:00');

        $this->org->forceFill(['suspended_at' => now()])->save();
        $this->addStoryChannel($this->scheduledPost(), '2026-11-20 11:00:00');

        $this->assertSame(0, app(SendUpcomingPostReminderEmailsAction::class)());

        Mail::assertNothingOutgoing();
    }

    public function test_the_whatsapp_summary_mentions_the_stories(): void
    {
        $post = $this->scheduledPost();
        $this->addStoryChannel($post, '2026-11-20 10:00:00');
        $this->addFeedChannel($post, '2026-11-20 12:00:00');
        $this->addFeedChannel($post, '2026-11-21 12:00:00');

        app(SendUpcomingPostReminderEmailsAction::class)();

        Notification::assertSentTo(
            $this->member,
            UpcomingPublishReminder::class,
            fn (UpcomingPublishReminder $reminder) => $reminder->toWhatsApp($this->member)->values['post_summary']
                === '2 posts, 1 story to post by hand today',
        );
    }

    public function test_the_whatsapp_summary_is_unchanged_without_stories_and_stays_short_with_them(): void
    {
        $plain = new UpcomingPublishReminder(3, 'Autumn menu', now()->addHour());
        $this->assertSame('3 posts', $plain->toWhatsApp($this->member)->values['post_summary']);

        $single = new UpcomingPublishReminder(1, 'Autumn menu', now()->addHour());
        $this->assertSame('Autumn menu', $single->toWhatsApp($this->member)->values['post_summary']);

        $onlyStories = new UpcomingPublishReminder(2, 'Autumn menu', now()->addHour(), 2);
        $this->assertSame('2 stories to post by hand today', $onlyStories->toWhatsApp($this->member)->values['post_summary']);

        $many = new UpcomingPublishReminder(123456789, 'Autumn menu', now()->addHour(), 12345678);
        $this->assertLessThanOrEqual(60, mb_strlen($many->toWhatsApp($this->member)->values['post_summary']));
    }
}
