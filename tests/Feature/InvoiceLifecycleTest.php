<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Mail\InvoiceSentMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Invoicing v3: creating with payment terms, sending by email, reminders, payment details,
 * voiding, duplicating, and the delete guard.
 */
class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->org = Organization::factory()->create(['invoice_payment_terms_days' => 30]);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create([
            'billing_email' => 'accounts@client.test',
            'approval_email' => 'approvals@client.test',
            'retainer_amount' => null,
        ]);
    }

    private function draft(array $attributes = []): Invoice
    {
        return Invoice::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => InvoiceStatus::Draft,
            'amount' => 500,
            ...$attributes,
        ]);
    }

    private function sent(array $attributes = []): Invoice
    {
        return $this->draft([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now()->subDays(3),
            'sent_to_email' => 'accounts@client.test',
            ...$attributes,
        ]);
    }

    public function test_an_invoice_can_be_created_without_an_amount_and_gets_the_org_payment_terms(): void
    {
        $this->actingAs($this->owner)->post(route('clients.invoices.store', $this->client), [
            'issue_date' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('client_id', $this->client->id)->sole();
        $this->assertSame('0.00', $invoice->amount);
        $this->assertSame('2026-10-31', $invoice->due_date->toDateString());
    }

    public function test_an_explicit_due_date_overrides_the_payment_terms(): void
    {
        $this->actingAs($this->owner)->post(route('clients.invoices.store', $this->client), [
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-05',
            'amount' => 250,
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('client_id', $this->client->id)->sole();
        $this->assertSame('2026-10-05', $invoice->due_date->toDateString());
        $this->assertSame('250.00', $invoice->amount);
    }

    public function test_sending_emails_the_billing_contact_with_the_generated_pdf_and_notifies_portal_users(): void
    {
        Notification::fake();
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $invoice = $this->draft();

        $this->actingAs($this->owner)
            ->post(route('invoices.send', $invoice), ['message' => 'Thanks for a great month!'])
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('accounts@client.test', $invoice->sent_to_email);
        $this->assertTrue($invoice->hasPdf());
        $this->assertSame($invoice->generatedPdfFilename(), $invoice->pdf_original_filename);
        Storage::disk('public')->assertExists($invoice->pdf_path);

        Mail::assertSent(InvoiceSentMail::class, fn (InvoiceSentMail $mail) => $mail->hasTo('accounts@client.test')
            && $mail->personalMessage === 'Thanks for a great month!'
            && ! $mail->isReminder);
        Notification::assertSentTo($reviewer, InvoiceIssued::class);
    }

    public function test_sending_can_override_the_recipient(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->owner)
            ->post(route('invoices.send', $invoice), ['email' => 'cfo@client.test'])
            ->assertSessionHasNoErrors();

        Mail::assertSent(InvoiceSentMail::class, fn (InvoiceSentMail $mail) => $mail->hasTo('cfo@client.test'));
        $this->assertSame('cfo@client.test', $invoice->fresh()->sent_to_email);
    }

    public function test_sending_falls_back_to_the_approval_email(): void
    {
        $this->client->update(['billing_email' => null]);
        $invoice = $this->draft();

        $this->actingAs($this->owner)->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        Mail::assertSent(InvoiceSentMail::class, fn (InvoiceSentMail $mail) => $mail->hasTo('approvals@client.test'));
    }

    public function test_an_invoice_is_marked_sent_without_email_when_the_client_has_no_contact(): void
    {
        $this->client->update(['billing_email' => null, 'approval_email' => null]);
        $invoice = $this->draft();

        $this->actingAs($this->owner)->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNull($invoice->sent_to_email);
        Mail::assertNothingSent();
    }

    public function test_an_itemised_invoice_must_be_finalized_before_it_is_sent(): void
    {
        $invoice = $this->draft(['amount' => 0]);
        InvoiceItem::factory()->for($invoice)->create(['org_id' => $this->org->id, 'price' => 100, 'quantity' => 1, 'is_billable' => true]);

        $this->actingAs($this->owner)->post(route('invoices.send', $invoice))->assertSessionHasErrors('status');

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_a_zero_amount_invoice_cannot_be_sent(): void
    {
        $invoice = $this->draft(['amount' => 0]);

        $this->actingAs($this->owner)->post(route('invoices.send', $invoice))->assertSessionHasErrors('status');

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_sending_never_overwrites_a_manually_uploaded_pdf(): void
    {
        $invoice = $this->draft();
        $this->actingAs($this->owner)->post(route('invoices.pdf.store', $invoice), [
            'pdf' => UploadedFile::fake()->create('custom-invoice.pdf', 50, 'application/pdf'),
        ]);
        $manualPath = $invoice->fresh()->pdf_path;

        $this->actingAs($this->owner)->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame($manualPath, $invoice->pdf_path);
        $this->assertSame('custom-invoice.pdf', $invoice->pdf_original_filename);
    }

    public function test_marking_paid_records_the_payment_date_and_reference(): void
    {
        $invoice = $this->sent();

        $this->actingAs($this->owner)->post(route('invoices.mark-paid', $invoice), [
            'paid_at' => now()->subDay()->toDateString(),
            'payment_reference' => 'BACS 1234',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(now()->subDay()->toDateString(), $invoice->paid_at->toDateString());
        $this->assertSame('BACS 1234', $invoice->payment_reference);
    }

    public function test_a_payment_date_in_the_future_is_rejected(): void
    {
        $invoice = $this->sent();

        $this->actingAs($this->owner)->post(route('invoices.mark-paid', $invoice), [
            'paid_at' => now()->addWeek()->toDateString(),
        ])->assertSessionHasErrors('paid_at');

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    public function test_a_sent_invoice_can_be_voided_with_a_reason(): void
    {
        $invoice = $this->sent();

        $this->actingAs($this->owner)->post(route('invoices.void', $invoice), ['reason' => 'Issued in error'])
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertNotNull($invoice->voided_at);
        $this->assertSame('Issued in error', $invoice->void_reason);
    }

    public function test_draft_and_paid_invoices_cannot_be_voided(): void
    {
        $draft = $this->draft();
        $paid = $this->sent(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

        $this->actingAs($this->owner)->post(route('invoices.void', $draft))->assertSessionHasErrors('status');
        $this->actingAs($this->owner)->post(route('invoices.void', $paid))->assertSessionHasErrors('status');

        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $paid->fresh()->status);
    }

    public function test_a_designer_cannot_void_remind_or_duplicate(): void
    {
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $invoice = $this->sent();

        $this->actingAs($designer)->post(route('invoices.void', $invoice))->assertForbidden();
        $this->actingAs($designer)->post(route('invoices.remind', $invoice))->assertForbidden();
        $this->actingAs($designer)->post(route('invoices.duplicate', $invoice))->assertForbidden();
    }

    public function test_sent_and_paid_invoices_cannot_be_deleted_but_void_ones_can(): void
    {
        $sent = $this->sent();
        $paid = $this->sent(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        $void = $this->sent(['status' => InvoiceStatus::Void, 'voided_at' => now()]);

        $this->actingAs($this->owner)->delete(route('invoices.destroy', $sent))->assertSessionHasErrors('status');
        $this->actingAs($this->owner)->delete(route('invoices.destroy', $paid))->assertSessionHasErrors('status');
        $this->actingAs($this->owner)->delete(route('invoices.destroy', $void))->assertRedirect(route('clients.show', $this->client));

        $this->assertModelExists($sent);
        $this->assertModelExists($paid);
        $this->assertModelMissing($void);
    }

    public function test_a_reminder_emails_the_original_recipient_and_is_throttled(): void
    {
        $invoice = $this->sent(['sent_to_email' => 'original@client.test']);

        $this->actingAs($this->owner)->post(route('invoices.remind', $invoice))->assertSessionHasNoErrors();

        Mail::assertSent(InvoiceSentMail::class, fn (InvoiceSentMail $mail) => $mail->hasTo('original@client.test') && $mail->isReminder);
        $invoice->refresh();
        $this->assertSame(1, $invoice->reminder_count);
        $this->assertNotNull($invoice->last_reminded_at);

        $this->actingAs($this->owner)->post(route('invoices.remind', $invoice))->assertSessionHasErrors('invoice');
        $this->assertSame(1, $invoice->fresh()->reminder_count);
    }

    public function test_reminders_are_only_for_invoices_awaiting_payment(): void
    {
        $this->actingAs($this->owner)->post(route('invoices.remind', $this->draft()))->assertSessionHasErrors('invoice');

        Mail::assertNothingSent();
    }

    public function test_a_reminder_needs_somebody_to_email(): void
    {
        $this->client->update(['billing_email' => null, 'approval_email' => null]);
        $invoice = $this->sent(['sent_to_email' => null]);

        $this->actingAs($this->owner)->post(route('invoices.remind', $invoice))->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_duplicating_copies_items_into_a_fresh_draft(): void
    {
        $source = $this->sent([
            'invoice_number' => 'INV-000007',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'is_finalized' => true,
            'description' => 'September work',
            'currency' => 'EUR',
        ]);
        InvoiceItem::factory()->for($source)->create(['org_id' => $this->org->id, 'description' => 'Reel edit', 'price' => 120, 'quantity' => 2, 'sort_order' => 1]);
        InvoiceItem::factory()->for($source)->create(['org_id' => $this->org->id, 'description' => 'Strategy call', 'is_billable' => false, 'sort_order' => 2]);

        $response = $this->actingAs($this->owner)->post(route('invoices.duplicate', $source));

        $copy = Invoice::where('invoice_number', 'INV-000008')->sole();
        $response->assertRedirect(route('invoices.show', $copy));
        $this->assertSame(InvoiceStatus::Draft, $copy->status);
        $this->assertFalse($copy->is_finalized);
        $this->assertSame('September work', $copy->description);
        $this->assertSame('EUR', $copy->currency);
        $this->assertSame(Carbon::today()->toDateString(), $copy->issue_date->toDateString());
        $this->assertSame(Carbon::today()->addDays(14)->toDateString(), $copy->due_date->toDateString());
        $this->assertSame(['Reel edit', 'Strategy call'], $copy->items->pluck('description')->all());
    }

    public function test_the_detail_page_exposes_lifecycle_state_and_abilities(): void
    {
        $invoice = $this->sent(['due_date' => now()->subDays(5)->toDateString()]);

        $this->actingAs($this->owner)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/show')
                ->where('invoice.display_status', 'overdue')
                ->where('invoice.days_overdue', 5)
                ->where('invoice.recipient_email', 'accounts@client.test')
                ->has('invoice.timeline', 2)
                ->where('can.void', true)
                ->where('can.remind', true)
                ->where('can.mark_paid', true)
                ->where('can.delete', false)
                ->where('can.send', false)
            );
    }
}
