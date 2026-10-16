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

/**
 * Step 6.6 — the client portal's read-only invoices list/detail: every invoice belongs to the
 * logged-in contact's own client, unpaid ones are flagged, and another client's invoice 404s.
 */
class PortalInvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewer(Client $client): User
    {
        return User::factory()
            ->for($client->organization, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);
    }

    public function test_a_client_reviewer_sees_only_their_own_clients_invoices(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);

        $ownInvoice = Invoice::factory()->for($clientA)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);
        Invoice::factory()->for($clientB)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $response = $this->actingAs($reviewer)->get(route('portal.invoices.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/invoices/index')
            ->has('invoices', 1)
            ->where('invoices.0.id', $ownInvoice->id)
        );
    }

    public function test_unpaid_invoices_are_flagged_and_paid_invoices_are_not(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);

        $unpaid = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);
        $paid = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Paid]);

        $response = $this->actingAs($reviewer)->get(route('portal.invoices.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) use ($unpaid, $paid) {
            $invoices = collect($page->toArray()['props']['invoices']);

            $this->assertTrue($invoices->firstWhere('id', $unpaid->id)['is_unpaid']);
            $this->assertFalse($invoices->firstWhere('id', $paid->id)['is_unpaid']);
        });
    }

    public function test_a_client_reviewer_can_view_their_own_invoice_detail(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $response = $this->actingAs($reviewer)->get(route('portal.invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/invoices/show')
            ->where('invoice.id', $invoice->id)
        );
    }

    public function test_a_client_reviewer_gets_a_404_viewing_another_clients_invoice(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);

        $otherInvoice = Invoice::factory()->for($clientB)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $this->actingAs($reviewer)->get(route('portal.invoices.show', $otherInvoice))->assertNotFound();
    }

    public function test_draft_invoices_are_never_shown_to_the_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $draft = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $sent = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $this->actingAs($reviewer)->get(route('portal.invoices.index'))
            ->assertInertia(fn ($page) => $page->has('invoices', 1)->where('invoices.0.id', $sent->id));

        $this->actingAs($reviewer)->get(route('portal.invoices.show', $draft))->assertNotFound();
    }

    public function test_the_portal_detail_flags_overdue_invoices_and_shows_items_and_payment_instructions(): void
    {
        $org = Organization::factory()->create(['payment_instructions' => 'Sort code 00-00-00']);
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Sent,
            'due_date' => now()->subDays(2)->toDateString(),
            'void_reason' => 'internal only',
        ]);
        InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id, 'description' => 'Reel edit']);

        $this->actingAs($reviewer)->get(route('portal.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/invoices/show')
                ->where('invoice.is_overdue', true)
                ->where('invoice.display_status', 'overdue')
                ->where('invoice.items.0.description', 'Reel edit')
                ->missing('invoice.void_reason')
                ->where('studio.payment_instructions', 'Sort code 00-00-00')
            );
    }
}
