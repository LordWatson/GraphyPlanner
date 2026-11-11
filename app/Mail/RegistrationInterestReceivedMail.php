<?php

namespace App\Mail;

use App\Actions\SuperAdmin\GetRegistrationInterestStatsAction;
use App\Models\RegistrationInterest;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert: someone registered interest. Branded HTML with a plain-text part, linking to the
 * record in the super admin console. Reply-To is the applicant, so hitting reply starts the conversation.
 */
class RegistrationInterestReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RegistrationInterest $interest)
    {
        $this->onQueue(Queues::MAIL);

        $this->from(config('mail.registration_interest.from'), 'Alima registrations')
            ->replyTo($interest->email, $interest->name);
    }

    public function build(): self
    {
        return $this
            ->subject("New registration: {$this->interest->company_name} ({$this->interest->name})")
            ->view('emails.registration-interest-received')
            ->text('emails.registration-interest-received-text')
            ->with([
                'url' => route('super-admin.registrations.show', $this->interest),
                'source' => GetRegistrationInterestStatsAction::source($this->interest),
                // The team works UK hours; the promise on the form is 24 hours from now.
                'replyBy' => $this->interest->created_at->addDay()->timezone('Europe/London'),
            ]);
    }
}
