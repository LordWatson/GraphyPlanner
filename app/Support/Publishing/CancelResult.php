<?php

namespace App\Support\Publishing;

/**
 * Step I-3.6 / M-2.5 — the outcome of asking the vendor to cancel a scheduled publish.
 * `cancelled` is true only when the vendor confirmed it (or there was nothing scheduled to
 * cancel); otherwise `error` says why, and `tooLate` marks "it's already publishing", which the
 * caller must treat as "this will go out as originally scheduled".
 */
final readonly class CancelResult
{
    public function __construct(
        public bool $cancelled,
        public ?string $error = null,
        public bool $tooLate = false,
    ) {}

    public static function cancelled(): self
    {
        return new self(true);
    }

    public static function failed(string $error, bool $tooLate = false): self
    {
        return new self(false, $error, $tooLate);
    }
}
