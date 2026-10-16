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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Lifecycle actions (send / mark paid / void) now (re)generate the branded PDF and email
        // the client — keep both off the real disk and mailer.
        Storage::fake('public');
        Mail::fake();
    }

    public function test_owner_can_create_an_invoice_for_a_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);

        $invoice = Invoice::where('client_id', $client->id)->sole();
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($org->id, $invoice->org_id);
    }

    public function test_an_invoice_defaults_to_the_organizations_currency(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'issue_date' => now()->toDateString(),
        ]);

        $this->assertSame('GBP', Invoice::where('client_id', $client->id)->sole()->currency);
    }

    public function test_an_invoice_can_be_created_in_a_chosen_currency(): void
    {
        $org = Organization::factory()->create(['default_currency' => 'GBP']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'currency' => 'EUR',
            'amount' => 450,
            'issue_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('client_id', $client->id)->sole();
        $this->assertSame('EUR', $invoice->currency);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.currency', 'EUR'));
    }

    public function test_creating_an_invoice_rejects_an_unsupported_currency(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'currency' => 'XYZ',
            'issue_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('currency');
        $this->assertSame(0, Invoice::where('client_id', $client->id)->count());
    }

    public function test_invoice_numbers_do_not_collide_across_organizations_with_the_same_invoice_count(): void
    {
        // Regression test: invoice_number generation previously counted the org's existing
        // invoices (not the highest number actually used), and the column was globally unique —
        // so two different orgs that both happened to have, say, 5 invoices each would both try to
        // insert "INV-000006" and the second request would fail with a unique constraint violation.
        $orgA = Organization::factory()->create();
        $ownerA = User::factory()->for($orgA, 'organization')->role(Role::Owner)->create();
        $clientA = Client::factory()->for($orgA, 'organization')->create();
        for ($i = 1; $i <= 5; $i++) {
            Invoice::factory()->for($clientA)->create(['org_id' => $orgA->id, 'invoice_number' => sprintf('INV-%06d', $i)]);
        }

        $orgB = Organization::factory()->create();
        $ownerB = User::factory()->for($orgB, 'organization')->role(Role::Owner)->create();
        $clientB = Client::factory()->for($orgB, 'organization')->create();
        for ($i = 1; $i <= 5; $i++) {
            Invoice::factory()->for($clientB)->create(['org_id' => $orgB->id, 'invoice_number' => sprintf('INV-%06d', $i)]);
        }

        $responseA = $this->actingAs($ownerA)->post(route('clients.invoices.store', $clientA), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);
        $responseA->assertRedirect(route('invoices.show', Invoice::where('org_id', $orgA->id)->where('invoice_number', 'INV-000006')->sole()));

        $responseB = $this->actingAs($ownerB)->post(route('clients.invoices.store', $clientB), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);
        $responseB->assertRedirect(route('invoices.show', Invoice::where('org_id', $orgB->id)->where('invoice_number', 'INV-000006')->sole()));

        $this->assertDatabaseHas('invoices', ['org_id' => $orgA->id, 'invoice_number' => 'INV-000006']);
        $this->assertDatabaseHas('invoices', ['org_id' => $orgB->id, 'invoice_number' => 'INV-000006']);
    }

    public function test_invoice_number_is_not_reused_after_an_invoice_is_deleted(): void
    {
        // Regression test: generating the next number from a row count reused an already-used
        // number once any invoice for the org was deleted.
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoices = collect(range(1, 3))->map(fn (int $i) => Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'invoice_number' => sprintf('INV-%06d', $i),
        ]));
        $invoices->first()->delete();

        $response = $this->actingAs($owner)->post(route('clients.invoices.store', $client), [
            'amount' => 1000,
            'issue_date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('invoices.show', Invoice::where('invoice_number', 'INV-000004')->sole()));
        $this->assertDatabaseHas('invoices', [
            'org_id' => $org->id,
            'invoice_number' => 'INV-000004',
        ]);
        $this->assertDatabaseCount('invoices', 3);
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

        $response = $this->actingAs($owner)->from(route('clients.show', $client))->post(route('invoices.send', $invoice));

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

        $response = $this->actingAs($owner)->from(route('clients.show', $client))->post(route('invoices.mark-paid', $invoice));

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

    public function test_paid_invoice_does_not_expose_send_or_mark_paid_actions(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Paid,
            'sent_at' => now()->subDay(),
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('invoices.0.id', $invoice->id)
            ->where('invoices.0.can.send', false)
            ->where('invoices.0.can.mark_paid', false)
        );
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

    public function test_owner_can_view_an_invoice_detail_page(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('invoices/show')
            ->where('invoice.id', $invoice->id)
            ->where('can.update', true)
            ->where('can.delete', true)
        );
    }

    public function test_invoice_detail_page_exposes_items_and_subtotal_in_inertia_props(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $item = InvoiceItem::factory()->for($invoice)->create(['is_billable' => true, 'price' => 100, 'quantity' => 2]);
        InvoiceItem::factory()->for($invoice)->create(['is_billable' => false, 'price' => 999, 'quantity' => 1]);

        $response = $this->actingAs($owner)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('invoices/show')
            ->where('invoice.is_finalized', false)
            ->where('invoice.items_subtotal', 200)
            ->has('invoice.items', 2)
            ->where('invoice.items.0.id', $item->id)
            ->where('can.manage_items', true)
            ->where('can.finalize', true)
        );
    }

    public function test_manage_items_stays_true_once_an_invoice_is_finalized(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('invoices/show')
            ->where('invoice.is_finalized', true)
            ->where('can.manage_items', true)
            ->where('can.finalize', false)
        );
    }

    public function test_user_from_another_org_cannot_view_an_invoice_detail_page(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->get(route('invoices.show', $invoice));

        $response->assertForbidden();
    }

    public function test_owner_can_view_the_invoice_edit_page(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('invoices.edit', $invoice));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('invoices/edit')->where('invoice.id', $invoice->id));
    }

    public function test_designer_cannot_view_the_invoice_edit_page(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->get(route('invoices.edit', $invoice));

        $response->assertForbidden();
    }

    public function test_owner_can_update_an_invoices_details(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'amount' => 100]);

        $response = $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'amount' => 250.50,
            'issue_date' => $invoice->issue_date->toDateString(),
            'description' => 'Updated description',
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $invoice->refresh();
        $this->assertSame('250.50', $invoice->amount);
        $this->assertSame('Updated description', $invoice->description);
    }

    public function test_designer_cannot_update_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'amount' => 100]);

        $response = $this->actingAs($designer)->put(route('invoices.update', $invoice), [
            'amount' => 250.50,
            'issue_date' => $invoice->issue_date->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertSame('100.00', $invoice->fresh()->amount);
    }

    public function test_owner_can_delete_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('invoices.destroy', $invoice));

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_designer_cannot_delete_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->delete(route('invoices.destroy', $invoice));

        $response->assertForbidden();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_owner_can_upload_an_invoice_pdf(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $invoice->refresh();
        $this->assertSame('invoice.pdf', $invoice->pdf_original_filename);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('public')->assertExists($invoice->pdf_path);
    }

    public function test_uploading_a_new_invoice_pdf_replaces_the_old_file(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
        ]);
        $oldPath = $invoice->fresh()->pdf_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($owner)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
        ]);

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame('second.pdf', $invoice->fresh()->pdf_original_filename);
    }

    public function test_invoice_pdf_upload_rejects_non_pdf_files(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('invoice.png', 100, 'image/png'),
        ]);

        $response->assertSessionHasErrors('pdf');
    }

    public function test_designer_cannot_upload_an_invoice_pdf(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
    }

    public function test_owner_can_finalize_a_draft_invoice_with_items(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['retainer_amount' => 200]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create(['is_billable' => true, 'price' => 100, 'quantity' => 1]);

        $response = $this->actingAs($owner)->post(route('invoices.finalize', $invoice));

        $response->assertRedirect(route('invoices.show', $invoice));
        $invoice->refresh();
        $this->assertTrue($invoice->is_finalized);
        $this->assertSame('300.00', $invoice->amount);
        $this->assertSame('200.00', $invoice->retainer_amount_snapshot);
    }

    public function test_finalizing_an_invoice_with_no_items_and_no_retainer_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['retainer_amount' => null]);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);

        $response = $this->actingAs($owner)->post(route('invoices.finalize', $invoice));

        $response->assertSessionHasErrors('invoice');
        $this->assertFalse($invoice->fresh()->is_finalized);
    }

    public function test_designer_cannot_finalize_an_invoice(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create();

        $response = $this->actingAs($designer)->post(route('invoices.finalize', $invoice));

        $response->assertForbidden();
        $this->assertFalse($invoice->fresh()->is_finalized);
    }

    public function test_items_can_still_be_added_once_an_invoice_is_finalized_and_amount_is_refreshed(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::Draft,
            'amount' => 100,
            'retainer_amount_snapshot' => 100,
        ]);

        $response = $this->actingAs($owner)->post(route('invoices.items.store', $invoice), [
            'description' => 'New item',
            'is_billable' => true,
            'price' => 50,
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertDatabaseCount('invoice_items', 1);
        $invoice->refresh();
        $this->assertTrue($invoice->is_finalized);
        $this->assertSame('150.00', $invoice->amount);
    }

    public function test_amount_cannot_be_changed_once_an_invoice_is_finalized(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft, 'amount' => 300]);

        $response = $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'amount' => 999,
            'issue_date' => $invoice->issue_date->toDateString(),
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame('300.00', $invoice->fresh()->amount);
    }

    public function test_currency_can_be_changed_on_an_unfinalized_draft(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft, 'currency' => 'USD']);

        $this->actingAs($owner)->get(route('invoices.edit', $invoice))
            ->assertInertia(fn ($page) => $page->where('can.edit_currency', true)->has('currencies'));

        $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'currency' => 'CAD',
            'issue_date' => $invoice->issue_date->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('CAD', $invoice->fresh()->currency);
    }

    public function test_omitting_the_currency_on_update_keeps_the_current_one(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft, 'currency' => 'EUR']);

        $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'issue_date' => $invoice->issue_date->toDateString(),
            'description' => 'Updated notes',
        ])->assertSessionHasNoErrors();

        $this->assertSame('EUR', $invoice->fresh()->currency);
    }

    public function test_currency_cannot_be_changed_once_an_invoice_is_finalized(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft, 'currency' => 'USD']);

        $this->actingAs($owner)->get(route('invoices.edit', $invoice))
            ->assertInertia(fn ($page) => $page->where('can.edit_currency', false));

        $response = $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'currency' => 'EUR',
            'issue_date' => $invoice->issue_date->toDateString(),
        ]);

        $response->assertSessionHasErrors('currency');
        $this->assertSame('USD', $invoice->fresh()->currency);
    }

    public function test_currency_cannot_be_changed_once_an_invoice_is_sent(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent, 'sent_at' => now(), 'currency' => 'USD']);

        $response = $this->actingAs($owner)->put(route('invoices.update', $invoice), [
            'currency' => 'EUR',
            'issue_date' => $invoice->issue_date->toDateString(),
        ]);

        $response->assertSessionHasErrors('currency');
        $this->assertSame('USD', $invoice->fresh()->currency);
    }

    public function test_owner_can_generate_a_branded_pdf_for_a_finalized_invoice(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create(['is_billable' => true, 'price' => 100, 'quantity' => 1]);

        $response = $this->actingAs($owner)->post(route('invoices.generate-pdf', $invoice));

        $response->assertRedirect(route('invoices.show', $invoice));
        $invoice->refresh();
        $this->assertNotNull($invoice->pdf_path);
        $this->assertSame('application/pdf', $invoice->pdf_mime_type);
        Storage::disk('public')->assertExists($invoice->pdf_path);
    }

    public function test_generating_a_pdf_for_an_itemised_invoice_that_is_not_finalized_is_rejected(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('invoices.generate-pdf', $invoice));

        $response->assertSessionHasErrors('invoice');
        $this->assertNull($invoice->fresh()->pdf_path);
    }

    public function test_designer_cannot_generate_an_invoice_pdf(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->finalized()->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        InvoiceItem::factory()->for($invoice)->create();

        $response = $this->actingAs($designer)->post(route('invoices.generate-pdf', $invoice));

        $response->assertForbidden();
        $this->assertNull($invoice->fresh()->pdf_path);
    }
}
