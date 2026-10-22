<?php

namespace Tests\Feature\Business;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostWaitingForInternalReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Step B-3.2 — the one-tap "Schedule" / "Post now" / "Send for review" action for a business
 * workspace.
 */
class QuickSchedulePostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Post, 3: SocialAccount}
     */
    private function businessDraft(bool $requireReview = false, bool $withTimes = true): array
    {
        $organization = Organization::factory()->business()->create([
            'require_post_review' => $requireReview,
        ]);
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        // LinkedIn is text-only-capable, so the checklist passes on caption + schedule, no media.
        $account = SocialAccount::factory()->for($brand)->connected()->create([
            'org_id' => $organization->id,
            'platform' => Platform::LinkedIn,
            'timezone' => 'Europe/London',
        ]);

        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
        ]);

        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => $withTimes ? now()->addDay()->toDateString() : null,
            'scheduled_local_time' => $withTimes ? '09:00' : null,
            'scheduled_at_utc' => $withTimes ? now()->addDay() : null,
        ]);

        return [$organization, $owner, $post->refresh(), $account];
    }

    public function test_owner_schedules_a_draft_in_one_tap(): void
    {
        Queue::fake();
        [, $owner, $post] = $this->businessDraft();

        $this->actingAs($owner)
            ->post(route('posts.quick-schedule', $post), ['when' => 'schedule'])
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);
        // draft → approved → scheduled: one activity-log row per hop, one approval row.
        $this->assertSame(2, $post->activityLogs()->count());
        $this->assertSame(1, $post->approvals()->count());
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->postId === $post->id);
    }

    public function test_post_now_retimes_targets_and_schedules_immediately(): void
    {
        Queue::fake();
        [, $owner, $post, $account] = $this->businessDraft(withTimes: false);

        $this->actingAs($owner)
            ->post(route('posts.quick-schedule', $post), ['when' => 'now'])
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame(PostStatus::Scheduled, $post->status);

        $target = $post->targets()->first();
        $this->assertNotNull($target->scheduled_at_utc);
        $this->assertTrue($target->scheduled_at_utc->lessThanOrEqualTo(now()->addMinute()));
        Queue::assertPushed(PublishPostJob::class);
    }

    public function test_review_on_routes_to_internal_review_and_notifies_owners(): void
    {
        Notification::fake();
        [$organization, $owner, $post] = $this->businessDraft(requireReview: true);
        // A Manager (Strategist) who should get the review ping, and a Creator (Designer) who
        // shouldn't (Step B-3.3: Owners/Managers only).
        $manager = User::factory()->for($organization, 'organization')->role(Role::Strategist)->create();
        $designer = User::factory()->for($organization, 'organization')->role(Role::Designer)->create();

        $this->actingAs($owner)
            ->post(route('posts.quick-schedule', $post), ['when' => 'schedule'])
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame(PostStatus::InternalReview, $post->status);

        // Managers hear about it; the acting owner (the actor) and the Creator don't.
        Notification::assertSentTo($manager, PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($owner, PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($designer, PostWaitingForInternalReview::class);
    }

    public function test_checklist_failure_leaves_the_post_untouched(): void
    {
        Queue::fake();
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        // No targets at all → the checklist can never pass.
        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($owner)
            ->from(route('posts.edit', $post))
            ->post(route('posts.quick-schedule', $post), ['when' => 'schedule'])
            ->assertSessionHasErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(0, $post->activityLogs()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_viewer_cannot_quick_schedule(): void
    {
        [$organization, , $post] = $this->businessDraft();
        $viewer = User::factory()->for($organization, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->post(route('posts.quick-schedule', $post), ['when' => 'schedule'])
            ->assertForbidden();

        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $post->targets()->first()->status);
    }
}
