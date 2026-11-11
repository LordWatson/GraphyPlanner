<?php

namespace App\Enums;

enum RegistrationInterestStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case DemoBooked = 'demo_booked';
    case Onboarded = 'onboarded';
    case NotAFit = 'not_a_fit';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::DemoBooked => 'Demo booked',
            self::Onboarded => 'Onboarded',
            self::NotAFit => 'Not a fit',
        };
    }
}
