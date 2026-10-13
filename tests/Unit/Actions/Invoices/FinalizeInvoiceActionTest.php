<?php

namespace Tests\Unit\Actions\Invoices;

use App\Actions\Invoices\FinalizeInvoiceAction;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinalizeInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_billable_items_and_adds_the_clients_retainer(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create(['retainer_amount' => 500]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        InvoiceItem::factory()->for($invoice)->create(['is_billable' => true, 'price' => 100, 'quantity' => 2]);
        InvoiceItem::factory()->for($invoice)->create(['is_billable' => false, 'price' => 999, 'quantity' => 1]);

        $result = (new FinalizeInvoiceAction)($invoice);

        $this->assertTrue($result->is_finalized);
        $this->assertSame('500.00', $result->retainer_amount_snapshot);
        $this->assertSame('700.00', $result->amount);
    }

    public function test_it_treats_a_null_retainer_as_zero(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create(['retainer_amount' => null]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        InvoiceItem::factory()->for($invoice)->create(['is_billable' => true, 'price' => 100, 'quantity' => 1]);

        $result = (new FinalizeInvoiceAction)($invoice);

        $this->assertSame('0.00', $result->retainer_amount_snapshot);
        $this->assertSame('100.00', $result->amount);
    }

    public function test_it_rejects_finalizing_an_invoice_with_no_items(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $this->expectException(ValidationException::class);

        (new FinalizeInvoiceAction)($invoice);
    }

    public function test_it_rejects_finalizing_a_non_draft_invoice(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);
        InvoiceItem::factory()->for($invoice)->create();

        $this->expectException(ValidationException::class);

        (new FinalizeInvoiceAction)($invoice);
    }

    public function test_it_rejects_finalizing_an_already_finalized_invoice(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create();

        $this->expectException(ValidationException::class);

        (new FinalizeInvoiceAction)($invoice);
    }
}
