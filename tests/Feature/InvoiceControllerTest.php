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

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_an_invoice_for_a_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('invoices', [
            'client_id' => $client->id,
            'org_id' => $org->id,
            'status' => InvoiceStatus::Draft->value,
        ]);
    }

    public function test_designer_cannot_create_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.invoices.store', $client), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_owner_can_send_a_draft_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->post(route('invoices.send', $invoice));

        $response->assertRedirect(route('clients.show', $client));
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_invoice_cannot_be_sent_twice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.send', $invoice));

        $response->assertSessionHasErrors('status');
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_owner_can_mark_a_sent_invoice_as_paid(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->sent()->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.mark-paid', $invoice));

        $response->assertRedirect(route('clients.show', $client));
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_draft_invoice_cannot_be_marked_as_paid(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->post(route('invoices.mark-paid', $invoice));

        $response->assertSessionHasErrors('status');
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_client_reviewer_does_not_see_invoices_on_the_client_page(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Invoice::factory()->for($client)->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('invoices', []));
    }

    public function test_user_from_another_org_cannot_send_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->post(route('invoices.send', $invoice));

        $response->assertForbidden();
    }
}
