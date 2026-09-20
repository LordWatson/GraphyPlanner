<?php

namespace App\Support\Options;

/**
 * Curated list of ISO 4217 currency codes offered as dropdown options wherever an
 * organization's default_currency (or similar currency field) is captured in the UI.
 */
final class CurrencyOptions
{
    /**
     * @var array<string, string>
     */
    private const CURRENCIES = [
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'NZD' => 'New Zealand Dollar',
        'CHF' => 'Swiss Franc',
        'SEK' => 'Swedish Krona',
        'NOK' => 'Norwegian Krone',
        'DKK' => 'Danish Krone',
        'PLN' => 'Polish Złoty',
        'JPY' => 'Japanese Yen',
        'CNY' => 'Chinese Yuan',
        'INR' => 'Indian Rupee',
        'BRL' => 'Brazilian Real',
        'MXN' => 'Mexican Peso',
        'ZAR' => 'South African Rand',
        'AED' => 'UAE Dirham',
        'SGD' => 'Singapore Dollar',
        'HKD' => 'Hong Kong Dollar',
    ];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $label) => ['value' => $code, 'label' => $label],
            array_keys(self::CURRENCIES),
            array_values(self::CURRENCIES),
        );
    }
}
