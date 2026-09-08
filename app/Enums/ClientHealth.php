<?php

namespace App\Enums;

enum ClientHealth: string
{
    case Red = 'red';
    case Amber = 'amber';
    case Green = 'green';

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red',
            self::Amber => 'Amber',
            self::Green => 'Green',
        };
    }
}
