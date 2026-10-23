<?php

namespace App\Mail;

use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step M-6.7 (I-12.1) — the daily notification digest email: a tidy list of the day's updates the
 * recipient opted to keep in their digest.
 */
class NotificationDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public string $name,
        public array $items,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject('Your Alima daily digest — '.count($this->items).' update'.(count($this->items) === 1 ? '' : 's'))
            ->view('emails.notification-digest');
    }
}
