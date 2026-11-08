<?php

namespace App\Support\WhatsApp;

/**
 * Step W-0.1 — what came back from one send attempt: Meta's message id on success, or its error
 * code with a reason a human can act on.
 *
 * Deliberately carries no raw vendor payload: this ends up in a log line and (Step W-3.1) in the
 * `whatsapp_messages` row, and a WhatsApp payload echoes the recipient's number and the message
 * body back at us.
 */
final readonly class WhatsAppSendResult
{
    private function __construct(
        public bool $ok,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorReason = null,
    ) {}

    public static function sent(string $messageId): self
    {
        return new self(ok: true, messageId: $messageId);
    }

    public static function failed(?string $code, string $reason): self
    {
        return new self(ok: false, errorCode: $code, errorReason: $reason);
    }

    /**
     * No credentials for this organization — nothing was sent and nobody was called. Distinct from
     * a failure so a caller can tell "WhatsApp is off here" from "WhatsApp is broken".
     */
    public static function notConfigured(): self
    {
        return self::failed('not_configured', 'WhatsApp is not configured for this organization.');
    }
}
