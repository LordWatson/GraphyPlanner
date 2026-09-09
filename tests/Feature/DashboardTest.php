<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_shows_the_clients_count_for_the_users_organization()
    {
        $user = User::factory()->create();
        Client::factory()->count(2)->create(['org_id' => $user->org_id]);

        // Clients belonging to another organization must not be counted.
        Client::factory()->count(3)->create();

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('summary.clients', 2));
    }

    public function test_dashboard_shows_monthly_invoice_totals_for_owner()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);

        Invoice::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => InvoiceStatus::Paid,
            'amount' => 500,
            'issue_date' => now()->startOfMonth(),
            'sent_at' => now()->startOfMonth(),
            'paid_at' => now(),
        ]);
        Invoice::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => InvoiceStatus::Sent,
            'amount' => 300,
            'issue_date' => now()->startOfMonth(),
            'sent_at' => now()->startOfMonth(),
        ]);

        // Invoices from another organization must not be counted.
        Invoice::factory()->create([
            'status' => InvoiceStatus::Paid,
            'amount' => 999,
            'sent_at' => now(),
            'paid_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('invoiceTotals', 6)
            ->where('invoiceTotals.5.invoiced', 800)
            ->where('invoiceTotals.5.paid', 500)
        );
    }

    public function test_dashboard_hides_invoice_totals_from_roles_without_billing_access()
    {
        $user = User::factory()->role(Role::Designer)->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);

        Invoice::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'status' => InvoiceStatus::Paid,
            'amount' => 500,
            'issue_date' => now()->startOfMonth(),
            'sent_at' => now()->startOfMonth(),
            'paid_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('invoiceTotals', []));
    }

    public function test_dashboard_shows_upcoming_posts_scheduled_in_the_next_seven_days()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $user->org_id, 'timezone' => 'UTC']);

        $post = Post::factory()->for($client)->create(['org_id' => $user->org_id]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDays(2)->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDays(2),
        ]);

        // A target scheduled beyond the 7-day window must not appear.
        $farPost = Post::factory()->for($client)->create(['org_id' => $user->org_id]);
        $farPost->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDays(30)->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDays(30),
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('upcomingPosts', 1)
            ->where('upcomingPosts.0.post_id', $post->id)
        );
    }

    public function test_client_reviewer_only_sees_their_own_clients_upcoming_posts()
    {
        $reviewer = User::factory()->role(Role::ClientReviewer)->create();
        $ownClient = Client::factory()->create(['org_id' => $reviewer->org_id]);
        $reviewer->forceFill(['client_id' => $ownClient->id])->save();
        $otherClient = Client::factory()->create(['org_id' => $reviewer->org_id]);

        $ownAccount = SocialAccount::factory()->for($ownClient)->create(['org_id' => $reviewer->org_id, 'timezone' => 'UTC']);
        $otherAccount = SocialAccount::factory()->for($otherClient)->create(['org_id' => $reviewer->org_id, 'timezone' => 'UTC']);

        $ownPost = Post::factory()->for($ownClient)->create(['org_id' => $reviewer->org_id]);
        $ownPost->targets()->create([
            'social_account_id' => $ownAccount->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDay(),
        ]);

        $otherPost = Post::factory()->for($otherClient)->create(['org_id' => $reviewer->org_id]);
        $otherPost->targets()->create([
            'social_account_id' => $otherAccount->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDay(),
        ]);

        $this->actingAs($reviewer);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('upcomingPosts', 1)
            ->where('upcomingPosts.0.post_id', $ownPost->id)
        );
    }
}
