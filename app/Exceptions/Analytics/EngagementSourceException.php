<?php

namespace App\Exceptions\Analytics;

use RuntimeException;

/**
 * Step M-3.1 — an engagement vendor call failed (HTTP error, vendor-reported error, bad shape).
 * The message is safe to store and show (`metrics_error`): it never contains a key or token.
 */
class EngagementSourceException extends RuntimeException {}
