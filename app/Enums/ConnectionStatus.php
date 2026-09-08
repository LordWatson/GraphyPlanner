<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case NotConnected = 'not_connected';
    case Connected = 'connected';
    case TokenExpired = 'token_expired';

    public function label(): string
    {
        return match ($this) {
            self::NotConnected => 'Not connected',
            self::Connected => 'Connected',
            self::TokenExpired => 'Token expired',
        };
    }
}
