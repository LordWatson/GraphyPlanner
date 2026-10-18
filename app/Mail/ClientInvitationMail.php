<?php

namespace App\Mail;

use App\Models\ClientInvitation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly-invited client contact (via Resend) inviting them to the Step 6.2 client
 * portal, containing a signed accept-invite link (a fresh, single-use `ClientInvitation` token).
 */
class ClientInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClientInvitation $invitation,
        public string $acceptUrl,
    ) {
        // Step I-2.8: every mailable runs on the mail queue.
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("You're invited to the {$this->invitation->client->name} client portal — Alima")
            ->view('emails.client-invitation');
    }
}
