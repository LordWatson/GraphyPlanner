<?php

namespace App\Services\Observability;

use App\Contracts\ErrorTracker;
use Throwable;

/**
 * No error-tracking DSN configured (local, tests, CI): exceptions are still logged by Laravel,
 * just not sent anywhere.
 */
class NullErrorTracker implements ErrorTracker
{
    public function capture(Throwable $exception): void {}
}
