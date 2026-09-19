<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
}
