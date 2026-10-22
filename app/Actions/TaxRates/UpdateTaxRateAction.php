<?php

namespace App\Actions\TaxRates;

use App\Models\TaxRate;
use Illuminate\Support\Facades\Log;

/**
 * Step M-4.1 — rename a rate or change its percentage. Existing invoices keep the rate they
 * snapshotted, so editing a rate only affects line items added afterwards.
 */
class UpdateTaxRateAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(TaxRate $taxRate, array $data): TaxRate
    {
        $taxRate->update(['name' => (string) $data['name'], 'rate' => $data['rate']]);

        Log::info('Tax rate updated', ['org_id' => $taxRate->org_id, 'tax_rate_id' => $taxRate->id]);

        return $taxRate;
    }
}
