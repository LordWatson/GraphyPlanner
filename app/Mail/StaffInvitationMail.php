<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly-invited staff member (via Resend) inviting them to join the organization,
 * containing a signed accept-invite link (a fresh, single-use `StaffInvitation` token).
 */
class StaffInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public StaffInvitation $invitation,
        public string $acceptUrl,
    ) {
        // Step I-2.8: every mailable runs on the mail queue.
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("You're invited to join {$this->invitation->organization->name} on Alima")
            ->view('emails.staff-invitation');
    }
}
