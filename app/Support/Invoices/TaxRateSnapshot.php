<?php

namespace App\Support\Invoices;

use App\Models\TaxRate;

/**
 * Step M-4.1 — resolve a chosen `tax_rate_id` (on a line item or a fixed-amount invoice) into the
 * `tax_rate` / `tax_name` snapshot stored alongside it, so archiving or editing the rate later
 * never alters a row that already carries it. A null `tax_rate_id` clears the snapshot.
 */
final class TaxRateSnapshot
{
    /**
     * Fill `tax_rate` / `tax_name` from `tax_rate_id` when it's present in `$data`, scoped to the
     * given org so another org's rate can never be snapshotted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function apply(array $data, int $orgId): array
    {
        if (! array_key_exists('tax_rate_id', $data)) {
            return $data;
        }

        $rate = $data['tax_rate_id'] === null
            ? null
            : TaxRate::query()->withoutOrgScope()->where('org_id', $orgId)->find((int) $data['tax_rate_id']);

        $data['tax_rate_id'] = $rate?->id;
        $data['tax_rate'] = $rate?->rate;
        $data['tax_name'] = $rate?->name;

        return $data;
    }
}
