<?php

namespace App\Actions\TaxRates;

use App\Models\Organization;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Log;

/**
 * Step M-4.1 — add a tax rate to an organization's list.
 */
class CreateTaxRateAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Organization $organization, array $data): TaxRate
    {
        $taxRate = TaxRate::query()->create([
            'org_id' => $organization->id,
            'name' => (string) $data['name'],
            'rate' => $data['rate'],
            'sort_order' => ((int) TaxRate::query()->where('org_id', $organization->id)->max('sort_order')) + 1,
        ]);

        Log::info('Tax rate created', ['org_id' => $organization->id, 'tax_rate_id' => $taxRate->id]);

        return $taxRate;
    }
}
