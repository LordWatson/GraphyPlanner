<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
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
}
