<?php

namespace App\Enums;

/**
 * Step GTM-2.1 — how a contact got onto the list. It is kept because it decides what we may say
 * about consent: only the person themselves ever sets `consent_at`, so the source is a record of
 * where they met the brand, never a licence to email them.
 */
enum AudienceSource: string
{
    case Order = 'order';
    case BioCapture = 'bio_capture';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Bought something',
            self::BioCapture => 'Signed up on your page',
            self::Import => 'Imported',
        };
    }
}
