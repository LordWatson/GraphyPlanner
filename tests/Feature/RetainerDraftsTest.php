<?php

namespace Tests\Feature;

use App\Actions\Invoices\GenerateRetainerDraftsAction;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-4.3 — recurring retainer drafts: opt-in, idempotent per period, drafts only.
 */
class RetainerDraftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_it_drafts_a_retainer_invoice_once_per_period_for_an_opted_in_client(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00'));

        $org = Organization::factory()->create(['default_currency' => 'GBP', 'default_timezone' => 'Europe/London']);
        $client = Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 1200,
            'billing_cycle' => BillingCycle::Monthly,
            'billing_currency' => 'GBP',
            'auto_invoice_retainer' => true,
        ]);

        $created = app(GenerateRetainerDraftsAction::class)();
        $this->assertSame(1, $created);

        $invoice = Invoice::where('client_id', $client->id)->sole();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('GBP', $invoice->currency);
        $this->assertSame('2026-11', $invoice->retainer_period);

        // Idempotent: a second run in the same period creates nothing more.
        $this->assertSame(0, app(GenerateRetainerDraftsAction::class)());
        $this->assertSame(1, Invoice::where('client_id', $client->id)->count());
    }

    public function test_quarterly_clients_get_a_quarter_tagged_draft(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00'));

        $org = Organization::factory()->create(['default_timezone' => 'UTC']);
        $client = Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 3000,
            'billing_cycle' => BillingCycle::Quarterly,
            'auto_invoice_retainer' => true,
        ]);

        app(GenerateRetainerDraftsAction::class)();

        $this->assertSame('2026-Q4', Invoice::where('client_id', $client->id)->sole()->retainer_period);
    }

    public function test_it_skips_clients_that_did_not_opt_in_or_have_no_retainer(): void
    {
        $org = Organization::factory()->create();
        Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 1000,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_invoice_retainer' => false,
        ]);
        Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => null,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_invoice_retainer' => true,
        ]);

        $this->assertSame(0, app(GenerateRetainerDraftsAction::class)());
        $this->assertSame(0, Invoice::count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
