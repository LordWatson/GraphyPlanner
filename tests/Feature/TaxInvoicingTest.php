<?php

namespace Tests\Feature;

use App\Actions\Campaigns\CreateInvoiceFromCampaignAction;
use App\Actions\Invoices\FinalizeInvoiceAction;
use App\Enums\CampaignBillingType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Step M-4.1 — VAT/TRN, per-org tax rates, tax on invoices, per-client billing currency.
 */
class TaxInvoicingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_edit_and_archive_a_tax_rate(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->post(route('tax-rates.store'), ['name' => 'UK VAT', 'rate' => 20])
            ->assertRedirect(route('organization.edit'));

        $rate = TaxRate::where('org_id', $org->id)->sole();
        $this->assertSame('20.00', $rate->rate);

        $this->actingAs($owner)->put(route('tax-rates.update', $rate), ['name' => 'UK VAT standard', 'rate' => 17.5]);
        $this->assertSame('UK VAT standard', $rate->fresh()->name);

        $this->actingAs($owner)->post(route('tax-rates.archive', $rate));
        $this->assertTrue($rate->fresh()->isArchived());
    }

    public function test_a_designer_cannot_manage_tax_rates(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->post(route('tax-rates.store'), ['name' => 'VAT', 'rate' => 20])
            ->assertForbidden();
    }

    public function test_a_tax_rate_from_another_org_cannot_be_edited(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $otherRate = TaxRate::factory()->create();

        $this->actingAs($owner)->put(route('tax-rates.update', $otherRate), ['name' => 'Hijack', 'rate' => 0])
            ->assertForbidden();
    }

    public function test_an_invoice_defaults_to_the_clients_billing_currency(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['billing_currency' => 'AED']);

        $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'issue_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('AED', Invoice::where('client_id', $client->id)->sole()->currency);
    }

    public function test_a_fixed_amount_invoice_applies_the_clients_default_tax_rate(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $rate = TaxRate::factory()->for($org, 'organization')->create(['name' => 'VAT 20%', 'rate' => 20]);
        $client = Client::factory()->for($org, 'organization')->create(['default_tax_rate_id' => $rate->id]);

        $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('client_id', $client->id)->sole();
        $this->assertSame('1000.00', $invoice->subtotal);
        $this->assertSame('200.00', $invoice->tax_total);
        $this->assertSame('1200.00', $invoice->amount);
    }

    public function test_finalizing_taxes_items_and_the_retainer_and_groups_the_breakdown(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $rate = TaxRate::factory()->for($org, 'organization')->create(['name' => 'VAT 20%', 'rate' => 20]);
        $client = Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 500,
            'billing_currency' => 'GBP',
            'default_tax_rate_id' => $rate->id,
        ]);
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Draft,
            'currency' => 'GBP',
        ]);
        InvoiceItem::factory()->for($invoice)->create([
            'is_billable' => true,
            'price' => 100,
            'quantity' => 1,
            'tax_rate_id' => $rate->id,
            'tax_rate' => 20,
            'tax_name' => 'VAT 20%',
        ]);

        $result = (new FinalizeInvoiceAction)($invoice);

        // Net 100 (item) + 500 (retainer) = 600; VAT 20% = 120; total 720. Both lines are 20%,
        // so the breakdown merges to one row.
        $this->assertSame('600.00', $result->subtotal);
        $this->assertSame('120.00', $result->tax_total);
        $this->assertSame('720.00', $result->amount);
        $this->assertCount(1, $result->tax_breakdown);
        $this->assertSame('120.00', number_format((float) $result->tax_breakdown[0]['tax'], 2, '.', ''));
    }

    public function test_finalizing_a_retainer_invoice_in_another_currency_is_rejected(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $client = Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 500,
            'billing_currency' => 'GBP',
        ]);
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Draft,
            'currency' => 'AED',
        ]);

        $this->expectException(ValidationException::class);

        (new FinalizeInvoiceAction)($invoice);
    }

    public function test_a_campaign_invoice_inherits_the_clients_default_tax_rate(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $rate = TaxRate::factory()->for($org, 'organization')->create(['name' => 'VAT 5%', 'rate' => 5]);
        $client = Client::factory()->for($org, 'organization')->create(['default_tax_rate_id' => $rate->id]);
        $campaign = Campaign::factory()->for($client)->create([
            'org_id' => $org->id,
            'billing_type' => CampaignBillingType::FixedFee,
            'fee_amount' => 1000,
        ]);

        $invoice = app(CreateInvoiceFromCampaignAction::class)($campaign, $owner);

        $item = $invoice->items()->sole();
        $this->assertSame($rate->id, $item->tax_rate_id);
        $this->assertSame('5.00', $item->tax_rate);
    }

    public function test_a_tax_registered_org_renders_a_tax_invoice_with_numbers_and_tax_lines(): void
    {
        $org = Organization::factory()->create([
            'default_currency' => 'GBP',
            'tax_registration_number' => 'GB123456789',
            'tax_registration_label' => 'VAT',
        ]);
        $client = Client::factory()->for($org, 'organization')->create([
            'billing_currency' => 'GBP',
            'tax_registration_number' => 'GB987654321',
        ]);
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Sent,
            'currency' => 'GBP',
            'is_finalized' => true,
            'subtotal' => 1000,
            'tax_total' => 200,
            'tax_breakdown' => [['name' => 'VAT 20%', 'rate' => 20.0, 'net' => 1000.0, 'tax' => 200.0]],
            'amount' => 1200,
        ]);

        $html = view('pdf.invoice', [
            'invoice' => $invoice->load(['items', 'client', 'organization']),
            'client' => $invoice->client,
            'organization' => $invoice->organization,
            'items' => $invoice->items,
            'itemsSubtotal' => $invoice->itemsSubtotal(),
        ])->render();

        $this->assertStringContainsString('Tax invoice', $html);
        $this->assertStringContainsString('GB123456789', $html);
        $this->assertStringContainsString('GB987654321', $html);
        $this->assertStringContainsString('VAT 20%', $html);
    }
}
