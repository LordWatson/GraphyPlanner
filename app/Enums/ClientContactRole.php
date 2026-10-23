<?php

namespace App\Enums;

/**
 * Step M-6.1 — the kind of contact a named person at a client is. The role drives sensible email
 * defaults (an approver receives review emails, a billing contact receives invoices), which each
 * contact can still override per-contact.
 */
enum ClientContactRole: string
{
    case Approver = 'approver';
    case Billing = 'billing';
    case MarketingLead = 'marketing_lead';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Approver => 'Approver',
            self::Billing => 'Billing',
            self::MarketingLead => 'Marketing lead',
            self::Other => 'Other',
        };
    }

    /**
     * Whether this role receives post review emails by default when a contact is created.
     */
    public function defaultReceivesReviewEmails(): bool
    {
        return $this === self::Approver;
    }

    /**
     * Whether this role receives invoices by default when a contact is created.
     */
    public function defaultReceivesInvoices(): bool
    {
        return $this === self::Billing;
    }
}
