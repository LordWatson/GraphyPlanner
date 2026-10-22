<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Step B-1.4 — client invoicing is agency-only. A business workspace bills on its own
     * subscription (Phase B-9) and never touches client invoices, so every ability here is denied
     * for it (defence in depth behind the `workspace:agency` route guard). Returning null leaves
     * super admins (allowed by the global `Gate::before`) and agencies to the per-ability checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->organization?->isBusiness() === true ? false : null;
    }

    /**
     * Determine whether the user can view the invoice history for a client.
     */
    public function viewAny(User $user, Client $client): bool
    {
        return $user->can('viewBilling', $client);
    }

    /**
     * Determine whether the user can view the organization-wide invoice ledger (and export it).
     */
    public function viewLedger(User $user): bool
    {
        return $user->org_id !== null
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can view a specific invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id && $user->can('viewBilling', $invoice->client);
    }

    /**
     * Determine whether the user can create invoices for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can send an invoice to the client for billing.
     */
    public function send(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can mark an invoice as paid.
     */
    public function markPaid(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can update an invoice's details or PDF.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can finalize an invoice (lock in its total).
     */
    public function finalize(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can delete an invoice.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can confirm (or revert) a payment the client reported from the
     * portal (Step M-4.2).
     */
    public function confirmPayment(User $user, Invoice $invoice): bool
    {
        return $this->manages($user, $invoice);
    }

    /**
     * Determine whether the user can void a sent invoice.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $this->manages($user, $invoice);
    }

    /**
     * Determine whether the user can send a payment reminder for an invoice.
     */
    public function remind(User $user, Invoice $invoice): bool
    {
        return $this->manages($user, $invoice);
    }

    /**
     * Determine whether the user can start a new draft from an existing invoice.
     */
    public function duplicate(User $user, Invoice $invoice): bool
    {
        return $this->manages($user, $invoice);
    }

    /**
     * Same-org Owner/Strategist — the billing roles.
     */
    private function manages(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
