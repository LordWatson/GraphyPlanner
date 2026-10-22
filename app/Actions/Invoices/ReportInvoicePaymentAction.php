<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoicePaymentReported;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-4.2 — the client (from the read-only portal) reports that they've paid a sent invoice.
 * This does NOT mark it paid: it moves `sent → pending_confirmation` and records what the client
 * said (method / reference / note), then tells the agency's billing users to confirm it. Graphy
 * never processes money — the org and client settle out-of-band and this only records that they
 * believe it happened.
 */
class ReportInvoicePaymentAction
{
    /**
     * @param  array{method?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function __invoke(Invoice $invoice, array $data = []): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'Only a sent, unpaid invoice can be reported as paid.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'status' => InvoiceStatus::PendingConfirmation,
                'payment_reported_at' => now(),
                'payment_reported_method' => $data['method'] ?? null,
                'payment_reported_reference' => $data['reference'] ?? null,
                'payment_reported_note' => $data['note'] ?? null,
            ]);

            DB::afterCommit(fn () => $this->notifyStudio($invoice));

            Log::info('Invoice payment reported by client', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
            ]);

            return $invoice;
        });
    }

    private function notifyStudio(Invoice $invoice): void
    {
        User::query()
            ->where('org_id', $invoice->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->get()
            ->each(fn (User $user) => $user->notify(new InvoicePaymentReported($invoice)));
    }
}
