<?php

namespace App\Rules;

use App\Support\WhatsApp\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Step W-1.2 — a number WhatsApp could actually reach.
 *
 * Used where consent is being recorded, because a consent with an undialable number is a promise
 * nothing can keep: `PhoneNumber::e164()` refuses a national number rather than guessing a country,
 * so the person filling the form is the one who gets to resolve it.
 */
class SendableWhatsAppNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::e164($value) === null) {
            $fail('Enter the number with its country code, for example +44 7700 900123.');
        }
    }
}
