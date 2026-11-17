<?php

namespace App\Mail;

use App\Models\Organization;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Sent to every user of an organization to warn them that one or more `PostTarget`s are due to
 * be published within the reminder window (see `SendUpcomingPostReminderEmailsAction`).
 *
 * Step S-3.4: `$storyTimes` are the local times of the assisted Stories due today. When there are
 * any, the email opens with a heads-up (`$storyHeadsUp`) so whoever posts them knows before the
 * reminder arrives. With `$storiesOnly`, there is nothing new going out soon and the email is
 * about today's Stories alone: `$occurrences` are those Stories, and the subject says so.
 */
class UpcomingPostReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** "2 stories to post by hand today (10:00, 16:30)." — null when there are none. */
    public ?string $storyHeadsUp;

    /** The subject, and the heading inside. */
    public string $headline;

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @param  list<string>  $storyTimes  `H:i`, each in its own channel's timezone, earliest first
     */
    public function __construct(
        public Organization $organization,
        public array $occurrences,
        public int $days,
        array $storyTimes = [],
        public bool $storiesOnly = false,
    ) {
        $this->storyHeadsUp = self::storyHeadsUp($storyTimes);
        $count = count($occurrences);
        $this->headline = match (true) {
            $storiesOnly => $count.' '.Str::plural('story', $count).' to post by hand today',
            $count === 1 => 'A post is scheduled to go out soon',
            default => "{$count} posts are scheduled to go out soon",
        };

        // Step I-2.8: every mailable runs on the mail queue.
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject($this->headline)
            ->view('emails.upcoming-post-reminder');
    }

    /**
     * @param  list<string>  $times
     */
    public static function storyHeadsUp(array $times): ?string
    {
        $count = count($times);

        if ($count === 0) {
            return null;
        }

        return $count.' '.Str::plural('story', $count).' to post by hand today ('.implode(', ', $times).').';
    }
}
