<?php

namespace App\Support\Publishing;

/**
 * Result of a single PublishAdapter::publish() target attempt, mirroring spec §7's
 * `TargetResult` shape so every adapter (Upload-Post, future vendors) reports
 * publish outcomes in the same way.
 */
final readonly class TargetResult
{
    /**
     * @param  array<int, string>  $sentFields
     * @param  array<int, string>  $skippedFields
     */
    public function __construct(
        public int $accountId,
        public bool $ok,
        public ?string $externalPostId = null,
        public ?string $error = null,
        public array $sentFields = [],
        public array $skippedFields = [],
    ) {}
}
