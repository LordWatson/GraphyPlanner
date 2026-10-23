<?php

namespace App\Support\ClientReports;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;

/**
 * Step M-3.3 — who a published monthly report is emailed to: the client's portal reviewers, then
 * its review-email contacts (Step M-6.1, falling back to the legacy approval email), de-duplicated
 * case-insensitively. Never staff.
 */
final class ClientReportRecipients
{
    /**
     * @return list<array{name: string|null, email: string}>
     */
    public static function for(Client $client): array
    {
        $recipients = [];

        User::query()
            ->where('org_id', $client->org_id)
            ->where('client_id', $client->id)
            ->where('role', Role::ClientReviewer)
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->each(function (User $contact) use (&$recipients) {
                $recipients[strtolower($contact->email)] ??= ['name' => $contact->name, 'email' => $contact->email];
            });

        foreach ($client->reviewEmailRecipients() as $contact) {
            $recipients[strtolower($contact['email'])] ??= $contact;
        }

        return array_values($recipients);
    }
}
