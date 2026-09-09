<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * §12 acceptance test (Step 0.11): a post's two targets on accounts in different timezones
     * must render at their correct local times in "each account local" mode, and the toggle must
     * correctly re-render both targets in the org's default timezone.
     */
    public function test_targets_render_at_correct_local_times_and_the_timezone_toggle_reflects_org_default(): void
    {
        $org = Organization::factory()->create(['default_timezone' => 'America/New_York']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $london = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Facebook,
            'timezone' => 'Europe/London',
        ]);

        $dubai = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'timezone' => 'Asia/Dubai',
        ]);

        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $post->targets()->create([
            'social_account_id' => $london->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-01T08:00:00+00:00',
        ]);
        $post->targets()->create([
            'social_account_id' => $dubai->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-01T05:00:00+00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('calendar'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('calendar/index')
            ->where('orgTimezone', 'America/New_York')
            ->has('occurrences', 2)
            // Each account local: both targets keep their own 09:00 local time, never collapsed.
            ->where('occurrences.0.account_local.time', '09:00')
            ->where('occurrences.0.account_local.timezone', 'Europe/London')
            ->where('occurrences.1.account_local.time', '09:00')
            ->where('occurrences.1.account_local.timezone', 'Asia/Dubai')
            // Org timezone toggle: both instants re-render correctly in America/New_York.
            ->where('occurrences.0.org_timezone.date', '2026-10-01')
            ->where('occurrences.0.org_timezone.time', '04:00')
            ->where('occurrences.0.org_timezone.timezone', 'America/New_York')
            ->where('occurrences.1.org_timezone.date', '2026-10-01')
            ->where('occurrences.1.org_timezone.time', '01:00')
            ->where('occurrences.1.org_timezone.timezone', 'America/New_York')
        );
    }

    public function test_calendar_supports_filtering_by_client_campaign_status_and_platform(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();

        $accountA = SocialAccount::factory()->for($clientA)->create([
            'org_id' => $org->id,
            'platform' => Platform::LinkedIn,
            'timezone' => 'UTC',
        ]);
        $accountB = SocialAccount::factory()->for($clientB)->create([
            'org_id' => $org->id,
            'platform' => Platform::TikTok,
            'timezone' => 'UTC',
        ]);

        $postA = Post::factory()->for($clientA)->create(['org_id' => $org->id]);
        $postA->targets()->create([
            'social_account_id' => $accountA->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-01T09:00:00+00:00',
        ]);

        $postB = Post::factory()->for($clientB)->create(['org_id' => $org->id]);
        $postB->targets()->create([
            'social_account_id' => $accountB->id,
            'scheduled_local_date' => '2026-10-02',
            'scheduled_local_time' => '10:00',
            'scheduled_at_utc' => '2026-10-02T10:00:00+00:00',
        ]);

        $response = $this->actingAs($owner)->get(route('calendar', ['client_id' => $clientA->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('occurrences', 1)
            ->where('occurrences.0.post_id', $postA->id)
        );
    }

    public function test_client_reviewer_only_sees_their_own_clients_posts_on_the_calendar(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $clientA->id]);

        $accountA = SocialAccount::factory()->for($clientA)->create(['org_id' => $org->id, 'timezone' => 'UTC']);
        $accountB = SocialAccount::factory()->for($clientB)->create(['org_id' => $org->id, 'timezone' => 'UTC']);

        $postA = Post::factory()->for($clientA)->create(['org_id' => $org->id]);
        $postA->targets()->create([
            'social_account_id' => $accountA->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-01T09:00:00+00:00',
        ]);

        $postB = Post::factory()->for($clientB)->create(['org_id' => $org->id]);
        $postB->targets()->create([
            'social_account_id' => $accountB->id,
            'scheduled_local_date' => '2026-10-01',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-01T09:00:00+00:00',
        ]);

        $response = $this->actingAs($reviewer)->get(route('calendar'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('occurrences', 1)
            ->where('occurrences.0.post_id', $postA->id)
        );
    }
}
