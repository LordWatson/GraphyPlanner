<?php

namespace App\Actions\TaxRates;

use App\Models\Organization;
use App\Models\TaxRate;

/**
 * Step M-4.1 — seed an organization's starter tax rates (UK + UAE presets from `config/tax.php`).
 * Idempotent on `name`, so it's safe to call on an org that already has some rates (e.g. a
 * backfill): existing names are left untouched.
 */
class SeedDefaultTaxRatesAction
{
    public function __invoke(Organization $organization): void
    {
        /** @var list<array{name: string, rate: float}> $defaults */
        $defaults = config('tax.defaults', []);

        foreach ($defaults as $index => $default) {
            TaxRate::query()->firstOrCreate(
                ['org_id' => $organization->id, 'name' => $default['name']],
                ['rate' => $default['rate'], 'sort_order' => $index],
            );
        }
    }
}
