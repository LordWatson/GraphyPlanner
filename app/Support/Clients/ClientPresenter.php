<?php

namespace App\Support\Clients;

use App\Models\Client;

/**
 * Step I-2.4/I-2.5 — the client array shape shared by the roster, the client page header and the
 * edit form (moved out of `ClientController::transform`). Billing fields are null unless the
 * viewer may see billing. Health is read from the materialized columns (`Client::health()`).
 */
final class ClientPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function detail(Client $client, bool $canViewBilling): array
    {
        $health = $client->health();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'website' => $client->website,
            'industry' => $client->industry,
            'countries' => $client->countries,
            'status' => $client->status->value,
            'default_approval_mode' => $client->default_approval_mode->value,
            'owner_user_id' => $client->owner_user_id,
            'start_date' => $client->start_date?->toDateString(),
            'retainer_amount' => $canViewBilling ? $client->retainer_amount : null,
            'billing_cycle' => $canViewBilling ? $client->billing_cycle?->value : null,
            'tags' => $client->tags,
            'default_language' => $client->default_language,
            'notes_internal' => $client->notes_internal,
            'approval_email' => $client->approval_email,
            'billing_email' => $canViewBilling ? $client->billing_email : null,
            'billing_address' => $canViewBilling ? $client->billing_address : null,
            'health' => $health['status']->value,
            'health_reason' => $health['reason'],
        ];
    }

    /**
     * One roster row: the columns the index shows, plus its archive state.
     *
     * @return array<string, mixed>
     */
    public static function row(Client $client, bool $canViewBilling): array
    {
        $health = $client->health();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'industry' => $client->industry,
            'status' => $client->status->value,
            'default_language' => $client->default_language,
            'tags' => $client->tags ?? [],
            'owner_name' => $client->owner?->name,
            'start_date' => $client->start_date?->toDateString(),
            'retainer_amount' => $canViewBilling ? $client->retainer_amount : null,
            'billing_cycle' => $canViewBilling ? $client->billing_cycle?->value : null,
            'health' => $health['status']->value,
            'health_reason' => $health['reason'],
            'archived_at' => $client->deleted_at?->toIso8601String(),
            'has_invoices' => $client->getAttribute('has_invoices') === null ? null : (bool) $client->getAttribute('has_invoices'),
        ];
    }
}
