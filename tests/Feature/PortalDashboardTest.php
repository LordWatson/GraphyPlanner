<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
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
 * Step 6.4 — the client portal dashboard's needs-attention view: outstanding approvals, recently
 * requested changes, and upcoming scheduled posts, scoped to the logged-in contact's own client.
 */
class PortalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_waiting_approvals_changes_requested_and_upcoming_posts(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);

        $waiting = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        $changesRequested = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::ChangesRequested,
        ]);

        $scheduled = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::Scheduled,
        ]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);
        PostTarget::factory()->for($scheduled)->for($account, 'socialAccount')->create([
            'scheduled_at_utc' => now()->addDays(2),
        ]);

        // A draft should not appear anywhere on the dashboard.
        Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($reviewer)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/dashboard')
            ->where('items.waitingApprovals.0.post_id', $waiting->id)
            ->where('items.changesRequested.0.post_id', $changesRequested->id)
            ->where('items.upcomingPosts.0.post_id', $scheduled->id)
        );
    }

    public function test_dashboard_never_shows_another_clients_posts(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()
            ->for($org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $clientA->id]);

        Post::factory()->for($clientB)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        $response = $this->actingAs($reviewer)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/dashboard')
            ->where('items.waitingApprovals', [])
        );
    }
}
