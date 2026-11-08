<?php

namespace Tests\Unit\Support\WhatsApp;

use App\Support\WhatsApp\PhoneNumber;
use Tests\TestCase;

/**
 * Step W-0.2 — the two things a phone number has to do here: reach Meta in E.164, and reach a log
 * line as four digits.
 */
class PhoneNumberTest extends TestCase
{
    public function test_it_normalises_a_number_that_carries_its_country_code(): void
    {
        $this->assertSame('+447700900123', PhoneNumber::e164('+44 7700 900123'));
        $this->assertSame('+447700900123', PhoneNumber::e164('+44 (0) 7700-900123'));
        $this->assertSame('+447700900123', PhoneNumber::e164('00447700900123'));
        $this->assertSame('+447700900123', PhoneNumber::e164('  +447700900123  '));
        $this->assertSame('+12125550123', PhoneNumber::e164('+1 (212) 555-0123'));
    }

    public function test_a_number_without_a_country_code_is_refused_rather_than_guessed(): void
    {
        // The whole point: 07700 900123 is a real subscriber number in a dozen countries, and
        // picking one would message a stranger. W-1.1's verification asks for the full number.
        $this->assertNull(PhoneNumber::e164('07700 900123'));
        $this->assertNull(PhoneNumber::e164('7700900123'));
        $this->assertNull(PhoneNumber::e164('(212) 555-0123'));
    }

    public function test_it_refuses_what_cannot_be_a_number_at_all(): void
    {
        $this->assertNull(PhoneNumber::e164(null));
        $this->assertNull(PhoneNumber::e164(''));
        $this->assertNull(PhoneNumber::e164('+'));
        $this->assertNull(PhoneNumber::e164('not a phone'));
        // E.164 is 15 digits at most, and a country code never starts with 0.
        $this->assertNull(PhoneNumber::e164('+4477009001234567'));
        $this->assertNull(PhoneNumber::e164('+0447700900123'));
        $this->assertNull(PhoneNumber::e164('+44770'));
    }

    public function test_masking_leaves_only_the_last_four_digits(): void
    {
        $this->assertSame('***0123', PhoneNumber::mask('+44 7700 900123'));
        $this->assertSame('***0123', PhoneNumber::mask('+447700900123'));
        $this->assertNull(PhoneNumber::mask(null));
        $this->assertNull(PhoneNumber::mask('no digits here'));
    }
}
