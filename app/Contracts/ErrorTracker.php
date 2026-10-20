<?php

namespace App\Contracts;

use Throwable;

/**
 * Step I-16.1 / M-1.1 — where reported exceptions go (Sentry in production, nothing locally or in
 * tests). Every exception the handler reports is passed here (see `bootstrap/app.php`), and the
 * implementation tags it with the request's `Context` (correlation id, user, org, post), so a
 * failure can be traced back to the request or job that caused it.
 */
interface ErrorTracker
{
    public function capture(Throwable $exception): void;
}
