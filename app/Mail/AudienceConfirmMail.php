<?php

namespace App\Mail;

use App\Models\AudienceContact;
use App\Support\Audience\AudienceLinks;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step GTM-2.1 — "did you mean to sign up?". The one email we send to an address nobody has
 * confirmed yet, so it carries no marketing and no unsubscribe link: there is nothing to
 * unsubscribe from until the link in it is followed, and ignoring it is the unsubscribe.
 *
 * Sent from the creator's brand, like the store receipt: the person signed up to hear from them.
 */
class AudienceConfirmMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AudienceContact $contact)
    {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        $brand = $this->contact->client?->name ?? config('app.name');

        return $this->subject(__('Confirm your email for :brand', ['brand' => $brand]))
            ->view('emails.audience-confirm', [
                'brand' => $brand,
                'name' => $this->contact->name,
                'confirmUrl' => AudienceLinks::confirm($this->contact),
                'expiresInDays' => AudienceLinks::CONFIRM_TTL_DAYS,
            ]);
    }
}
