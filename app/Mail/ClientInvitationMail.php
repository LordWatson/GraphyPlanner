<?php

namespace App\Mail;

use App\Models\ClientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly-invited client contact (via Resend) inviting them to the Step 6.2 client
 * portal, containing a signed accept-invite link (a fresh, single-use `ClientInvitation` token).
 */
class ClientInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClientInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("You're invited to the {$this->invitation->client->name} client portal — Graphy")
            ->view('emails.client-invitation');
    }
}
