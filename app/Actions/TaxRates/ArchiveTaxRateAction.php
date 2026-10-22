<?php

namespace App\Actions\TaxRates;

use App\Models\TaxRate;
use Illuminate\Support\Facades\Log;

/**
 * Step M-4.1 — retire a tax rate. It's archived rather than deleted (invoices snapshot the rate,
 * and clients may still point at it as their default), so historical records stay intact; archived
 * rates simply drop out of the "add a line" picker. Passing `restore: true` un-archives it.
 */
class ArchiveTaxRateAction
{
    public function __invoke(TaxRate $taxRate, bool $restore = false): TaxRate
    {
        $taxRate->update(['archived_at' => $restore ? null : now()]);

        Log::info($restore ? 'Tax rate restored' : 'Tax rate archived', [
            'org_id' => $taxRate->org_id,
            'tax_rate_id' => $taxRate->id,
        ]);

        return $taxRate;
    }
}
