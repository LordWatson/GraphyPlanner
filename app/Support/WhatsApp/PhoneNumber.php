<?php

namespace App\Support\WhatsApp;

/**
 * Step W-0.2 — phone numbers in the one form Meta accepts, and in the one form we are allowed to
 * log.
 */
final class PhoneNumber
{
    /**
     * E.164 (`+447700900123`), or null when the input cannot be one.
     *
     * A number with no country code is **rejected rather than guessed**: `07700 900123` is a valid
     * subscriber number in a dozen countries, and guessing wrong messages a stranger. Verification
     * (Step W-1.1) is where someone is asked to supply the full number.
     *
     * shortcut: no per-org default dialling code. Worth adding if W-1.1 shows people routinely
     * pasting national numbers.
     */
    public static function e164(?string $number): ?string
    {
        $raw = trim((string) $number);
        // "+44 (0) 7700 900123" — the bracketed trunk zero belongs to the national format and is
        // the one digit that must go, not be dialled. Printed this way on half the business cards
        // in Europe.
        $digits = (string) preg_replace('/\D+/', '', (string) preg_replace('/\(\s*0\s*\)/', '', $raw));

        if (str_starts_with($digits, '00')) {
            // "00" is the international prefix the `+` stands in for.
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($raw, '+')) {
            return null;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? '+'.$digits : null;
    }

    /**
     * The last four digits only — the most a log line may ever say about a person's number.
     */
    public static function mask(?string $number): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $number);

        return $digits === '' ? null : '***'.substr($digits, -4);
    }
}
