<?php

namespace App\Support\WhatsApp;

/**
 * Step W-0.1 — what came back from one send attempt: the provider's message id and status on
 * success, or its error code with a reason a human can act on.
 *
 * Deliberately carries no raw vendor payload: this ends up in a log line and in a
 * `whatsapp_messages` row, and the payload echoes the recipient's number and the message body
 * back at us.
 *
 * A failure is classified (`WhatsAppFailure`) because two later steps need to act on the
 * difference: W-1.3 suppresses a number that is permanently unreachable, and W-3.2 retries a
 * transient failure but falls back to email on a closed window.
 */
final readonly class WhatsAppSendResult
{
    private function __construct(
        public bool $ok,
        public ?string $messageId = null,
        public ?string $providerStatus = null,
        public ?string $errorCode = null,
        public ?string $errorReason = null,
        public WhatsAppFailure $failure = WhatsAppFailure::None,
    ) {}

    public static function sent(string $messageId, ?string $providerStatus = null): self
    {
        return new self(ok: true, messageId: $messageId, providerStatus: $providerStatus);
    }

    public static function failed(?string $code, string $reason, WhatsAppFailure $failure = WhatsAppFailure::Transient): self
    {
        return new self(ok: false, errorCode: $code, errorReason: $reason, failure: $failure);
    }

    /**
     * No credentials for this organization — nothing was sent and nobody was called. Distinct from
     * a failure so a caller can tell "WhatsApp is off here" from "WhatsApp is broken".
     */
    public static function notConfigured(): self
    {
        return self::failed('not_configured', 'WhatsApp is not configured for this organization.', WhatsAppFailure::NotConfigured);
    }

    /**
     * Whether this number should stop being messaged altogether (Step W-1.3): it is not a WhatsApp
     * number, or its owner has opted out. Retrying either one is a nuisance to a stranger.
     */
    public function shouldSuppress(): bool
    {
        return $this->failure === WhatsAppFailure::Permanent;
    }

    public function isRetryable(): bool
    {
        return $this->failure === WhatsAppFailure::Transient;
    }
}
