<?php

namespace Tests\Unit\Models;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    /**
     * @return array<string, array{InvoiceStatus, int|null, string}>
     */
    public static function displayStatusCases(): array
    {
        return [
            'sent, due in future' => [InvoiceStatus::Sent, 3, 'sent'],
            'sent, due today' => [InvoiceStatus::Sent, 0, 'sent'],
            'sent, past due' => [InvoiceStatus::Sent, -1, 'overdue'],
            'sent, no due date' => [InvoiceStatus::Sent, null, 'sent'],
            'paid, past due' => [InvoiceStatus::Paid, -10, 'paid'],
            'draft, past due' => [InvoiceStatus::Draft, -10, 'draft'],
            'void, past due' => [InvoiceStatus::Void, -10, 'void'],
        ];
    }

    #[DataProvider('displayStatusCases')]
    public function test_overdue_is_derived_only_for_sent_invoices_past_their_due_date(InvoiceStatus $status, ?int $dueInDays, string $expected): void
    {
        $invoice = new Invoice([
            'status' => $status,
            'due_date' => $dueInDays === null ? null : Carbon::today()->addDays($dueInDays),
        ]);

        $this->assertSame($expected, $invoice->displayStatus());
        $this->assertSame($expected === 'overdue', $invoice->isOverdue());
        $this->assertSame($expected === 'overdue' ? -$dueInDays : 0, $invoice->daysOverdue());
    }

    public function test_pdf_generation_needs_a_locked_total(): void
    {
        $fixedAmount = new Invoice(['amount' => 100, 'is_finalized' => false]);
        $fixedAmount->setRelation('items', collect());

        $emptyDraft = new Invoice(['amount' => 0, 'is_finalized' => false]);
        $emptyDraft->setRelation('items', collect());

        $itemised = new Invoice(['amount' => 0, 'is_finalized' => false]);
        $itemised->setRelation('items', collect([new InvoiceItem(['is_billable' => true, 'price' => 10, 'quantity' => 1])]));

        $this->assertTrue($fixedAmount->canGeneratePdf());
        $this->assertFalse($emptyDraft->canGeneratePdf());
        $this->assertFalse($itemised->canGeneratePdf());
        $this->assertTrue($itemised->needsFinalizing());

        $itemised->is_finalized = true;
        $this->assertTrue($itemised->canGeneratePdf());
        $this->assertFalse($itemised->needsFinalizing());
    }

    public function test_a_manually_uploaded_pdf_is_never_refreshed(): void
    {
        $invoice = new Invoice(['invoice_number' => 'INV-000001', 'amount' => 100, 'pdf_disk' => 'public', 'pdf_path' => 'x.pdf']);
        $invoice->setRelation('items', collect());

        $invoice->pdf_original_filename = 'my-own-layout.pdf';
        $this->assertFalse($invoice->shouldRefreshGeneratedPdf());

        $invoice->pdf_original_filename = 'invoice-INV-000001.pdf';
        $this->assertTrue($invoice->shouldRefreshGeneratedPdf());
    }

    public function test_the_timeline_lists_only_events_that_happened_in_order(): void
    {
        $invoice = new Invoice([
            'finalized_at' => Carbon::parse('2026-10-02 09:00'),
            'sent_at' => Carbon::parse('2026-10-02 10:00'),
            'sent_to_email' => 'a@b.test',
            'paid_at' => Carbon::parse('2026-10-09 12:00'),
            'payment_reference' => 'REF1',
            'reminder_count' => 0,
        ]);
        $invoice->created_at = Carbon::parse('2026-10-01 08:00');

        $timeline = $invoice->timeline();

        $this->assertSame(['created', 'finalized', 'sent', 'paid'], array_column($timeline, 'event'));
        $this->assertSame('Emailed to a@b.test', $timeline[2]['detail']);
        $this->assertSame('Ref. REF1', $timeline[3]['detail']);
    }
}
