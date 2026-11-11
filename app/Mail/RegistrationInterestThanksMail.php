<?php

namespace App\Mail;

use App\Models\RegistrationInterest;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Confirms a "register interest" submission to the person who sent it, from the registrations
 * address (`mail.registration_interest.from`) so their reply reaches the team directly.
 */
class RegistrationInterestThanksMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RegistrationInterest $interest)
    {
        $this->onQueue(Queues::MAIL);

        $from = config('mail.registration_interest.from');
        $this->from($from, 'Alima')->replyTo($from, 'Alima');
    }

    public function build(): self
    {
        return $this
            ->subject("You're on the list — we'll be in touch soon")
            ->view('emails.registration-interest-thanks', [
                'firstName' => Str::before($this->interest->name, ' ') ?: $this->interest->name,
            ]);
    }
}
