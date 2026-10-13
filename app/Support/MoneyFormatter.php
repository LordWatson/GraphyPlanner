<?php

namespace App\Support;

use NumberFormatter;

/**
 * Formats amounts using a given ISO 4217 currency code's proper symbol/placement (e.g. "$100.00",
 * "€100,00", "CHF 100.00") instead of just concatenating the raw currency code in front of a plain
 * number. Used anywhere an invoice/amount is rendered outside the React frontend (which already
 * does this via Intl.NumberFormat in resources/js/lib/currency.ts) — currently the branded invoice
 * PDF. No currency conversion is performed, this only controls display formatting.
 */
final class MoneyFormatter
{
    public static function format(float $amount, string $currency): string
    {
        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);

        $formatted = $formatter->formatCurrency($amount, strtoupper($currency));

        return $formatted !== false ? $formatted : number_format($amount, 2).' '.strtoupper($currency);
    }
}
