<?php

namespace App\Mail;

use App\Models\ConnectInvite;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step M-2.8 — sent (via Resend) to a client contact with their self-connect link. The link
 * carries the raw token, which exists only in this email and the one-time screen; it's never
 * stored or logged.
 */
class ClientConnectInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ConnectInvite $invite,
        public string $connectUrl,
        public string $agencyName,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("Connect your social accounts for {$this->invite->client->name} — Alima")
            ->view('emails.client-connect-invite');
    }
}
