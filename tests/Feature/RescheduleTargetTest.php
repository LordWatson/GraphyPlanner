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
 * Step M-5.7 / I-5.1 — the calendar's single-channel drag (`POST post-targets/{target}/reschedule`):
 * only the dragged target moves (day, and from the time grid its time too); its siblings never
 * change, and local times are never collapsed together.
 */
class RescheduleTargetTest extends TestCase
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

    public function test_moving_one_target_leaves_its_siblings_unchanged(): void
    {
        $post = $this->makePost();
        $london = $this->target($post, 'Europe/London', '2026-10-20', '09:00', '2026-10-20T08:00:00+00:00');
        $dubai = $this->target($post, 'Asia/Dubai', '2026-10-20', '09:00', '2026-10-20T05:00:00+00:00');
        $london->update(['reminder_sent_at' => now()]);

        $this->actingAs($this->user(Role::Owner))
            ->from(route('calendar'))
            ->post(route('posts.targets.reschedule', $london), ['scheduled_local_date' => '2026-10-27'])
            ->assertRedirect(route('calendar'))
            ->assertSessionHasNoErrors();

        $london->refresh();
        $dubai->refresh();

        // The dragged channel moved; it kept its own local time.
        $this->assertSame('2026-10-27', $london->scheduled_local_date?->toDateString());
        $this->assertSame('09:00', $london->scheduled_local_time);
        // London leaves BST on 25 Oct, so 09:00 local is now 09:00 UTC, not 08:00.
        $this->assertSame('2026-10-27T09:00:00+00:00', $london->scheduled_at_utc?->toIso8601String());
        $this->assertNull($london->reminder_sent_at);

        // The sibling is untouched.
        $this->assertSame('2026-10-20', $dubai->scheduled_local_date?->toDateString());
        $this->assertSame('09:00', $dubai->scheduled_local_time);
        $this->assertSame('2026-10-20T05:00:00+00:00', $dubai->scheduled_at_utc?->toIso8601String());
    }

    public function test_the_time_grid_can_change_a_targets_time_as_well_as_its_day(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Strategist))
            ->post(route('posts.targets.reschedule', $target), [
                'scheduled_local_date' => '2026-10-22',
                'scheduled_local_time' => '14:00',
            ])
            ->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('2026-10-22', $target->scheduled_local_date?->toDateString());
        $this->assertSame('14:00', $target->scheduled_local_time);
        $this->assertSame('2026-10-22T14:00:00+00:00', $target->scheduled_at_utc?->toIso8601String());
    }

    public function test_a_target_cannot_be_moved_into_the_past(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Owner))
            ->post(route('posts.targets.reschedule', $target), ['scheduled_local_date' => '2026-10-10'])
            ->assertSessionHasErrors('scheduled_local_date');

        $this->assertSame('2026-10-20', $target->refresh()->scheduled_local_date?->toDateString());
    }

    public function test_the_date_is_required_and_the_time_must_be_well_formed(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');
        $owner = $this->user(Role::Owner);

        $this->actingAs($owner)->post(route('posts.targets.reschedule', $target), [])->assertSessionHasErrors('scheduled_local_date');
        $this->actingAs($owner)->post(route('posts.targets.reschedule', $target), [
            'scheduled_local_date' => '2026-10-22',
            'scheduled_local_time' => 'noon',
        ])->assertSessionHasErrors('scheduled_local_time');
    }

    public function test_viewers_cannot_move_a_target(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Viewer))
            ->post(route('posts.targets.reschedule', $target), ['scheduled_local_date' => '2026-10-22'])
            ->assertForbidden();
    }

    public function test_users_from_another_org_cannot_move_a_target(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');
        $outsider = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        $this->actingAs($outsider)
            ->post(route('posts.targets.reschedule', $target), ['scheduled_local_date' => '2026-10-22'])
            ->assertForbidden();
    }

    public function test_a_target_of_a_published_post_cannot_be_moved(): void
    {
        $post = $this->makePost(PostStatus::Published);
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->actingAs($this->user(Role::Owner))
            ->post(route('posts.targets.reschedule', $target), ['scheduled_local_date' => '2026-10-22'])
            ->assertForbidden();
    }

    public function test_preview_mode_cannot_move_a_target(): void
    {
        $post = $this->makePost();
        $target = $this->target($post, 'UTC', '2026-10-20', '09:00', '2026-10-20T09:00:00+00:00');

        $this->post(route('preview.posts.targets.reschedule', $target), ['scheduled_local_date' => '2026-10-22'])
            ->assertForbidden();
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
            'status' => PostTargetStatus::Pending,
        ]);
    }
}
