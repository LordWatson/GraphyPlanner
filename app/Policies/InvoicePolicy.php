<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Determine whether the user can view the invoice history for a client.
     */
    public function viewAny(User $user, Client $client): bool
    {
        return $user->can('viewBilling', $client);
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
}
