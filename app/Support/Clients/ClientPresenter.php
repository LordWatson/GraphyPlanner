<?php

namespace App\Support\Clients;

use App\Models\Client;
use App\Models\ClientHealthHistory;
use App\Models\User;

/**
 * Step I-2.4/I-2.5 — the client array shape shared by the roster, the client page header and the
 * edit form (moved out of `ClientController::transform`). Billing fields are null unless the
 * viewer may see billing. Health is read from the materialized columns (`Client::health()`).
 *
 * Step M-3.4: health comes through `Client::healthFor($viewer)` — the weighted reasons the viewer
 * may read (Owner-only ones dropped for everyone else) — and the detail adds the 90-day trend.
 */
final class ClientPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function detail(Client $client, bool $canViewBilling, ?User $viewer = null): array
    {
        $health = $client->healthFor($viewer);

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'website' => $client->website,
            'industry' => $client->industry,
            'countries' => $client->countries,
            'status' => $client->status->value,
            'default_approval_mode' => $client->default_approval_mode->value,
            'approval_policy' => $client->approval_policy->value,
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
            // Step M-4.1 / M-4.3: tax registration, per-client billing currency, default tax rate
            // and the recurring-retainer opt-in (all billing-gated).
            'tax_registration_number' => $canViewBilling ? $client->tax_registration_number : null,
            'billing_currency' => $canViewBilling ? $client->billing_currency : null,
            'default_tax_rate_id' => $canViewBilling ? $client->default_tax_rate_id : null,
            'auto_invoice_retainer' => $canViewBilling ? $client->auto_invoice_retainer : null,
            'health' => $health['status']->value,
            'health_reason' => $health['reason'],
            'health_reasons' => $health['reasons'],
            'health_trend' => self::trend($client),
            'target_posts_per_week' => $client->target_posts_per_week,
        ];
    }

    /**
     * Step M-3.4 — health over the last 90 days: the status at the start of the window, then every
     * change, oldest first (from `client_health_history`). Reasons are left out — an old reason may
     * have been Owner-only.
     *
     * @return list<array{status: string, at: string}>
     */
    public static function trend(Client $client, int $days = 90): array
    {
        $since = now()->subDays($days);
        $before = $client->healthHistory()->where('created_at', '<', $since)->latest('created_at')->latest('id')->first();
        $changes = $client->healthHistory()->where('created_at', '>=', $since)->oldest('created_at')->oldest('id')->get();

        return array_values(collect($before === null ? [] : [$before])
            ->merge($changes)
            ->map(fn (ClientHealthHistory $row) => [
                'status' => $row->health_status->value,
                'at' => ($row === $before ? $since : $row->created_at)?->toIso8601String() ?? $since->toIso8601String(),
            ])
            ->all());
    }

    /**
     * One roster row: the columns the index shows, plus its archive state.
     *
     * @return array<string, mixed>
     */
    public static function row(Client $client, bool $canViewBilling, ?User $viewer = null): array
    {
        $health = $client->healthFor($viewer);

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
            'health_reasons' => $health['reasons'],
            'archived_at' => $client->deleted_at?->toIso8601String(),
            'has_invoices' => $client->getAttribute('has_invoices') === null ? null : (bool) $client->getAttribute('has_invoices'),
        ];
    }
}
