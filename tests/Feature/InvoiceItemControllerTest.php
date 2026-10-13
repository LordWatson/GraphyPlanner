<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_line_item_to_a_draft_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->post(route('invoices.items.store', $invoice), [
            'description' => 'Reel edit',
            'is_billable' => true,
            'price' => 150,
            'quantity' => 2,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'org_id' => $org->id,
            'description' => 'Reel edit',
            'sort_order' => 1,
        ]);
    }

    public function test_designer_cannot_add_a_line_item(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($designer)->post(route('invoices.items.store', $invoice), [
            'description' => 'Reel edit',
            'is_billable' => true,
            'price' => 150,
            'quantity' => 1,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_adding_a_line_item_is_rejected_on_a_sent_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.items.store', $invoice), [
            'description' => 'Reel edit',
            'is_billable' => true,
            'price' => 150,
            'quantity' => 1,
        ]);

        $response->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_owner_can_update_a_line_item_on_a_draft_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id, 'description' => 'Old']);

        $response = $this->actingAs($owner)->put(route('invoice-items.update', $item), [
            'description' => 'New description',
            'is_billable' => true,
            'price' => 200,
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('New description', $item->fresh()->description);
    }

    public function test_designer_cannot_update_a_line_item(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id, 'description' => 'Old']);

        $response = $this->actingAs($designer)->put(route('invoice-items.update', $item), [
            'description' => 'New description',
            'is_billable' => true,
            'price' => 200,
            'quantity' => 1,
        ]);

        $response->assertForbidden();
        $this->assertSame('Old', $item->fresh()->description);
    }

    public function test_updating_a_line_item_is_rejected_on_a_sent_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id, 'description' => 'Old']);

        $response = $this->actingAs($owner)->put(route('invoice-items.update', $item), [
            'description' => 'New description',
            'is_billable' => true,
            'price' => 200,
            'quantity' => 1,
        ]);

        $response->assertSessionHasErrors('invoice');
        $this->assertSame('Old', $item->fresh()->description);
    }

    public function test_owner_can_delete_a_line_item_on_a_draft_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('invoice-items.destroy', $item));

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
    }

    public function test_designer_cannot_delete_a_line_item(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->delete(route('invoice-items.destroy', $item));

        $response->assertForbidden();
        $this->assertDatabaseHas('invoice_items', ['id' => $item->id]);
    }

    public function test_deleting_a_line_item_is_rejected_on_a_sent_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);
        $item = InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('invoice-items.destroy', $item));

        $response->assertSessionHasErrors('invoice');
        $this->assertDatabaseHas('invoice_items', ['id' => $item->id]);
    }

    public function test_user_from_another_org_cannot_add_a_line_item(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->post(route('invoices.items.store', $invoice), [
            'description' => 'Reel edit',
            'is_billable' => true,
            'price' => 150,
            'quantity' => 1,
        ]);

        $response->assertForbidden();
    }
}
