<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The organization-wide invoice ledger (`/invoices`) and its CSV export.
 */
class InvoiceLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exports / org settings sit behind `password.confirm` (Step M-1.2); not under test here.
        $this->confirmPassword();
    }

    public function test_owner_sees_the_org_ledger_with_summary_figures(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'amount' => 100, 'sent_at' => now(), 'due_date' => now()->subDays(3)]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'amount' => 50, 'sent_at' => now(), 'due_date' => now()->addDays(3)]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Paid, 'amount' => 200, 'sent_at' => now()->subDays(10), 'paid_at' => now()]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Void, 'amount' => 999]);

        // Another org's invoices never leak in.
        Invoice::factory()->create(['status' => InvoiceStatus::Sent, 'amount' => 5000]);

        $this->actingAs($owner)->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/index')
                ->has('invoices.data', 5)
                ->where('summary.outstanding_totals', [['currency' => 'USD', 'total' => 150]])
                ->where('summary.outstanding_count', 2)
                ->where('summary.overdue_totals', [['currency' => 'USD', 'total' => 100]])
                ->where('summary.overdue_count', 1)
                ->where('summary.paid_this_month_totals', [['currency' => 'USD', 'total' => 200]])
                ->where('summary.draft_count', 1)
                ->where('summary.average_days_to_pay', 10)
                ->has('clients', 1)
            );
    }

    public function test_the_ledger_sorts_by_a_whitelisted_column_and_rejects_others(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        foreach ([300, 100, 200] as $amount) {
            Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'amount' => $amount, 'sent_at' => now()]);
        }

        $this->actingAs($owner)->get(route('invoices.index', ['sort' => 'amount', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoices.data.0.amount', '100.00')
                ->where('invoices.data.2.amount', '300.00')
                ->where('filters.sort', 'amount')
                ->where('filters.direction', 'asc')
            );

        $this->actingAs($owner)->get(route('invoices.index', ['sort' => 'amount', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page->where('invoices.data.0.amount', '300.00'));

        $this->actingAs($owner)->get(route('invoices.index', ['sort' => 'client_id; drop table invoices']))
            ->assertSessionHasErrors('sort');
    }

    public function test_summary_totals_are_split_per_currency_with_the_org_default_first(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'currency' => 'USD', 'amount' => 40, 'sent_at' => now()]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'currency' => 'EUR', 'amount' => 75, 'sent_at' => now()]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'currency' => 'GBP', 'amount' => 100, 'sent_at' => now()]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'currency' => 'GBP', 'amount' => 25, 'sent_at' => now()]);

        $this->actingAs($owner)->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.outstanding_totals', [
                    ['currency' => 'GBP', 'total' => 125],
                    ['currency' => 'EUR', 'total' => 75],
                    ['currency' => 'USD', 'total' => 40],
                ])
                ->where('summary.outstanding_count', 4)
                ->where('summary.paid_this_month_totals', [])
                ->has('currencies')
            );
    }

    public function test_the_ledger_filters_by_derived_overdue_status(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $overdue = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->subDay()]);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->addDay()]);

        $this->actingAs($owner)->get(route('invoices.index', ['status' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('invoices.data', 1)
                ->where('invoices.data.0.id', $overdue->id)
                ->where('invoices.data.0.display_status', 'overdue')
                ->where('filters.status', 'overdue')
            );
    }

    public function test_the_ledger_searches_by_number_and_client_name(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $acme = Client::factory()->for($org, 'organization')->create(['name' => 'Acme Coffee']);
        $other = Client::factory()->for($org, 'organization')->create(['name' => 'Frzn']);
        $acmeInvoice = Invoice::factory()->for($acme)->create(['org_id' => $org->id, 'invoice_number' => 'INV-000001']);
        $otherInvoice = Invoice::factory()->for($other)->create(['org_id' => $org->id, 'invoice_number' => 'INV-000002']);

        $this->actingAs($owner)->get(route('invoices.index', ['search' => 'acme']))
            ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.id', $acmeInvoice->id));

        $this->actingAs($owner)->get(route('invoices.index', ['search' => '000002']))
            ->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.id', $otherInvoice->id));
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('invoices.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_designers_and_client_reviewers_cannot_see_the_ledger(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $client->id]);

        $this->actingAs($designer)->get(route('invoices.index'))->assertForbidden();
        $this->actingAs($designer)->get(route('invoices.export'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('invoices.index'))->assertForbidden();
    }

    public function test_the_ledger_exports_the_filtered_invoices_as_csv(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Acme Coffee']);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'invoice_number' => 'INV-000010', 'status' => InvoiceStatus::Paid, 'amount' => 1234.5, 'payment_reference' => 'BACS 9']);
        Invoice::factory()->for($client)->create(['org_id' => $org->id, 'invoice_number' => 'INV-000011', 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->get(route('invoices.export', ['status' => 'paid']));

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('"Invoice number",Client,Status', $lines[0]);
        $this->assertStringContainsString('INV-000010,"Acme Coffee",Paid', $lines[1]);
        $this->assertStringContainsString('1234.50', $lines[1]);
        $this->assertStringContainsString('"BACS 9"', $lines[1]);
    }
}
