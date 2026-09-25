<?php

namespace App\Exceptions\Publishing;

use RuntimeException;
use Throwable;

/**
 * Thrown by a MusicProvider (Step 1.8) when a search/find request can't reach a real result for
 * a reason the editor should actually be told about — no connected account for the platform, or
 * the vendor rejecting the request (e.g. TikTok reporting the connection needs to be
 * re-authenticated) — rather than the provider silently swallowing it into an empty Collection.
 * `PostController::musicSearch` catches this and surfaces `getMessage()` to the editor UI instead
 * of leaving a bare, unexplained empty result.
 */
class MusicProviderUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * A stable, frontend-facing code the editor UI can key off (Step 1.9.5), e.g.
     * `meta_not_connected` — distinct from the free-text message, which may vary per vendor
     * rejection reason. Null for providers that don't distinguish a specific reason.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
