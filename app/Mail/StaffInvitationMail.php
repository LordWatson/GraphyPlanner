<?php

namespace App\Mail;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly-invited staff member (via Resend) inviting them to join the organization,
 * containing a signed accept-invite link (a fresh, single-use `StaffInvitation` token).
 */
class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StaffInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("You're invited to join {$this->invitation->organization->name} on Graphy")
            ->view('emails.staff-invitation');
    }
}
