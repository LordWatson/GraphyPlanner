<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;

class InvoiceItemPolicy
{
    /**
     * Determine whether the user can add a line item to the given invoice.
     */
    public function create(User $user, Invoice $invoice): bool
    {
        return $user->org_id === $invoice->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can update a line item.
     */
    public function update(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->org_id === $invoiceItem->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can delete a line item.
     */
    public function delete(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->org_id === $invoiceItem->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
