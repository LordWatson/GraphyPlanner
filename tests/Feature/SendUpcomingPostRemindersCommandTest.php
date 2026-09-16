<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Mail\UpcomingPostReminderMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendUpcomingPostRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emails_every_org_user_about_a_post_scheduled_within_the_window(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->create();
        $designer = User::factory()->for($org, 'organization')->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Scheduled,
        ]);

        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => Carbon::now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => Carbon::now()->addDay(),
        ]);

        $this->artisan('posts:send-upcoming-reminders', ['--days' => 3])->assertSuccessful();

        Mail::assertSent(UpcomingPostReminderMail::class, 2);
        Mail::assertSent(UpcomingPostReminderMail::class, fn ($mail) => $mail->hasTo($owner->email));
        Mail::assertSent(UpcomingPostReminderMail::class, fn ($mail) => $mail->hasTo($designer->email));

        $this->assertNotNull($target->fresh()->reminder_sent_at);
    }

    public function test_it_does_not_send_a_reminder_twice_for_the_same_target(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Scheduled,
        ]);

        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => Carbon::now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => Carbon::now()->addDay(),
            'reminder_sent_at' => Carbon::now(),
        ]);

        $this->artisan('posts:send-upcoming-reminders', ['--days' => 3])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_it_ignores_posts_that_are_not_scheduled_or_outside_the_window(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        // Not scheduled yet (still draft) — must be skipped even if due soon.
        $draftPost = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Draft,
        ]);
        $draftPost->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => Carbon::now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => Carbon::now()->addDay(),
        ]);

        // Scheduled, but far outside the reminder window.
        $farPost = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Scheduled,
        ]);
        $farPost->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => Carbon::now()->addDays(30)->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => Carbon::now()->addDays(30),
        ]);

        $this->artisan('posts:send-upcoming-reminders', ['--days' => 3])->assertSuccessful();

        Mail::assertNothingSent();
    }
}
