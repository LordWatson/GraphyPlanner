<?php

namespace App\Exceptions;

use App\Models\DesignImportConnection;
use RuntimeException;

/**
 * Step M-5.9 — thrown when a design/cloud connection can no longer be used (revoked by the user at
 * the provider, an expired token that couldn't be refreshed, or any auth failure). Callers turn
 * this into a "reconnect your account" prompt rather than a generic error, and never leak token
 * details.
 */
class DesignImportReconnectRequired extends RuntimeException
{
    public function __construct(public readonly DesignImportConnection $connection, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'This connection needs reconnecting.');
    }
}
