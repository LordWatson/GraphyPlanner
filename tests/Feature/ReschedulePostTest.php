<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The calendar's drag-and-drop move (`POST posts/{post}/reschedule`): every dated target shifts
 * by the same number of days, keeping its own local time and timezone.
 */
class ReschedulePostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-17 12:00:00');

        $this->org = Organization::factory()->create(['default_timezone' => 'America/New_York']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_moving_a_post_shifts_every_target_and_keeps_each_local_time(): void
    {
        $owner = $this->user(Role::Owner);
        $post = $this->makePost();
        $london = $this->target($post, 'Europe/London', '2026-10-20', '09:00', '2026-10-20T08:00:00+00:00');
        $dubai = $this->target($post, 'Asia/Dubai', '2026-10-20', '09:00', '2026-10-20T05:00:00+00:00');
        $london->update(['reminder_sent_at' => now()]);

        $this->actingAs($owner)
            ->from(route('calendar'))
            ->post(route('posts.reschedule', $post), ['days' => 7])
            ->assertRedirect(route('calendar'));

        $london->refresh();
        $dubai->refresh();

        $this->assertSame('2026-10-27', $london->scheduled_local_date?->toDateString());
        $this->assertSame('09:00', $london->scheduled_local_time);
        // London leaves BST on 25 Oct, so 09:00 local is now 09:00 UTC, not 08:00.
        $this->assertSame('2026-10-27T09:00:00+00:00', $london->scheduled_at_utc?->toIso8601String());
        $this->assertNull($london->reminder_sent_at);

        $this->assertSame('2026-10-27', $dubai->scheduled_local_date?->toDateString());
        $this->assertSame('09:00', $dubai->scheduled_local_time);
        $this->assertSame('2026-10-27T05:00:00+00:00', $dubai->scheduled_at_utc?->toIso8601String());
    }

    public function test_a_post_can_be_moved_earlier(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-25', '10:00', '2026-10-25T10:00:00+00:00');

        $this->actingAs($this->user(Role::Strategist))
            ->post(route('posts.reschedule', $post), ['days' => -3])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-22', $target->refresh()->scheduled_local_date?->toDateString());
    }

    public function test_a_post_cannot_be_moved_into_the_past(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Owner))
            ->post(route('posts.reschedule', $post), ['days' => -3])
            ->assertSessionHasErrors('days');

        $this->assertSame('2026-10-20', $target->refresh()->scheduled_local_date?->toDateString());
    }

    public function test_moving_onto_today_is_rejected_when_the_time_has_already_passed(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-18', '09:00', '2026-10-18T09:00:00+00:00');

        $this->actingAs($this->user(Role::Owner))
            ->post(route('posts.reschedule', $post), ['days' => -1])
            ->assertSessionHasErrors('days');

        $this->assertSame('2026-10-18', $target->refresh()->scheduled_local_date?->toDateString());
    }

    public function test_days_must_be_a_non_zero_integer(): void
    {
        $post = $this->makePost();
        $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');
        $owner = $this->user(Role::Owner);

        $this->actingAs($owner)->post(route('posts.reschedule', $post), ['days' => 0])->assertSessionHasErrors('days');
        $this->actingAs($owner)->post(route('posts.reschedule', $post), ['days' => 'soon'])->assertSessionHasErrors('days');
        $this->actingAs($owner)->post(route('posts.reschedule', $post), [])->assertSessionHasErrors('days');
    }

    public function test_viewers_cannot_move_posts(): void
    {
        $post = $this->makePost();
        $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Viewer))
            ->post(route('posts.reschedule', $post), ['days' => 1])
            ->assertForbidden();
    }

    public function test_users_from_another_org_cannot_move_posts(): void
    {
        $post = $this->makePost();
        $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');
        $outsider = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        $this->actingAs($outsider)
            ->post(route('posts.reschedule', $post), ['days' => 1])
            ->assertForbidden();
    }

    public function test_locked_posts_cannot_be_moved(): void
    {
        $owner = $this->user(Role::Owner);

        foreach ([PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Published, PostStatus::Archived] as $status) {
            $post = $this->makePost($status);
            $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

            $this->actingAs($owner)
                ->post(route('posts.reschedule', $post), ['days' => 1])
                ->assertForbidden();

            $this->assertSame('2026-10-20', $target->refresh()->scheduled_local_date?->toDateString(), $status->value);
        }
    }

    public function test_a_post_with_an_already_published_target_cannot_be_moved(): void
    {
        $post = $this->makePost(PostStatus::Failed);
        $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00')
            ->update(['status' => PostTargetStatus::Published]);

        $this->actingAs($this->user(Role::Owner))
            ->post(route('posts.reschedule', $post), ['days' => 1])
            ->assertForbidden();
    }

    public function test_preview_mode_cannot_move_posts(): void
    {
        $post = $this->makePost();
        $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->post(route('preview.posts.reschedule', $post), ['days' => 1])->assertForbidden();
    }

    public function test_the_calendar_lists_only_the_posts_the_viewer_may_move(): void
    {
        $movable = $this->makePost();
        $this->target($movable, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');
        $scheduled = $this->makePost(PostStatus::Scheduled);
        $this->target($scheduled, 'UTC', '2026-10-21', '09:00', '2026-10-21T09:00:00+00:00');

        $this->actingAs($this->user(Role::Designer))
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page->where('reschedulablePostIds', [$movable->id]));

        $this->actingAs($this->user(Role::Viewer))
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page->where('reschedulablePostIds', []));
    }

    private function user(Role $role): User
    {
        return User::factory()->for($this->org, 'organization')->role($role)->create();
    }

    private function makePost(PostStatus $status = PostStatus::Draft): Post
    {
        return Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => $status]);
    }

    private function target(Post $post, string $timezone, string $date, string $time, string $utc): PostTarget
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Facebook,
            'timezone' => $timezone,
        ]);

        return $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => $date,
            'scheduled_local_time' => $time,
            'scheduled_at_utc' => $utc,
        ]);
    }
}
