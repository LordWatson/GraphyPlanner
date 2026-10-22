<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvoicePaymentReported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step M-4.2 — the client reports payment from the portal; the org confirms or reverts. Graphy
 * never processes money.
 */
class PaymentHandshakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Mail::fake();
    }

    private function reviewer(Client $client): User
    {
        return User::factory()->for($client->organization, 'organization')
            ->role(Role::ClientReviewer)->create(['client_id' => $client->id]);
    }

    public function test_a_client_can_report_payment_on_their_own_sent_invoice(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->reviewer($client);
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $this->actingAs($reviewer)->post(route('portal.invoices.report-payment', $invoice), [
            'method' => 'Bank transfer',
            'reference' => 'TRX-9',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PendingConfirmation, $invoice->status);
        $this->assertSame('Bank transfer', $invoice->payment_reported_method);
        $this->assertNotNull($invoice->payment_reported_at);

        Notification::assertSentTo($owner, InvoicePaymentReported::class);
    }

    public function test_a_client_cannot_report_payment_on_a_draft_or_another_clients_invoice(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->reviewer($clientA);

        $draft = Invoice::factory()->for($clientA)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Draft]);
        $this->actingAs($reviewer)->post(route('portal.invoices.report-payment', $draft))->assertNotFound();

        $otherSent = Invoice::factory()->for($clientB)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);
        $this->actingAs($reviewer)->post(route('portal.invoices.report-payment', $otherSent))->assertNotFound();
    }

    public function test_the_org_confirms_a_reported_payment_as_paid(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::PendingConfirmation,
            'payment_reported_reference' => 'TRX-9',
        ]);

        $this->actingAs($owner)->post(route('invoices.confirm-payment', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        // The client's reported reference carries over when the org supplies none.
        $this->assertSame('TRX-9', $invoice->payment_reference);
    }

    public function test_the_org_reverts_a_reported_payment_back_to_sent(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => InvoiceStatus::PendingConfirmation,
            'payment_reported_method' => 'Cash',
        ]);

        $this->actingAs($owner)->post(route('invoices.revert-payment', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->payment_reported_method);
    }

    public function test_the_org_can_still_mark_a_sent_invoice_paid_directly(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::Sent]);

        $this->actingAs($owner)->post(route('invoices.mark-paid', $invoice))->assertRedirect();

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_a_pending_confirmation_invoice_cannot_be_voided(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::PendingConfirmation]);

        $this->actingAs($owner)->post(route('invoices.void', $invoice), ['reason' => 'nope'])
            ->assertSessionHasErrors('status');

        $this->assertSame(InvoiceStatus::PendingConfirmation, $invoice->refresh()->status);
    }

    public function test_a_designer_cannot_confirm_a_payment(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invoice = Invoice::factory()->for($client)->create(['org_id' => $org->id, 'status' => InvoiceStatus::PendingConfirmation]);

        $this->actingAs($designer)->post(route('invoices.confirm-payment', $invoice))->assertForbidden();
    }
}
