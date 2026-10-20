<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Step I-16.1 / M-1.1 — the on-call email for an operational alert (see `NotifyingOpsAlerter`).
 * Internal only; carries ids, never secrets. Sent inline rather than queued.
 */
class OpsAlertMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $alertKey,
        public string $alertMessage,
        public array $context,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("[Alima ops] {$this->alertMessage}")
            ->text('emails.ops-alert');
    }
}
