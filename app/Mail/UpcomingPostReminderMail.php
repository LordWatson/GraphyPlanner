<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to every user of an organization to warn them that one or more `PostTarget`s are due to
 * be published within the reminder window (see `SendUpcomingPostReminderEmailsAction`).
 */
class UpcomingPostReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function __construct(
        public Organization $organization,
        public array $occurrences,
        public int $days,
    ) {}

    public function build(): self
    {
        $count = count($this->occurrences);

        return $this
            ->subject($count === 1
                ? 'A post is scheduled to go out soon'
                : "{$count} posts are scheduled to go out soon")
            ->view('emails.upcoming-post-reminder');
    }
}
