<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step 15.2.3 — welcomes the first Owner of a newly registered organization (via Resend). When a
 * super admin onboarded the org on the customer's behalf, `$setPasswordUrl` carries a password
 * reset link so the owner can choose their own password before signing in.
 */
class OrganizationWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public ?string $setPasswordUrl = null,
    ) {
        // Step I-2.8: every mailable runs on the mail queue.
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        $organization = $this->owner->organization;
        $isBusiness = $organization?->isBusiness() ?? false;

        return $this
            ->subject("Welcome to Alima — {$organization?->name} is ready")
            ->view('emails.organization-welcome', [
                'organizationName' => $organization?->name,
                // Step B-2.1: a business/creator workspace sees no agency language ("client",
                // "studio"). Its call to action points at the guided setup, not the agency dashboard.
                'isBusiness' => $isBusiness,
                'dashboardUrl' => $isBusiness ? route('business.onboarding') : route('dashboard'),
                'forgotPasswordUrl' => route('password.request'),
            ]);
    }
}
