<?php

namespace App\Notifications;

use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Step W-2.2 — "this goes out soon", on WhatsApp.
 *
 * **WhatsApp only.** The same sweep already emails every member a digest
 * (`UpcomingPostReminderMail`), so adding a bell notification here would be a third copy of the
 * same news; a member who wants it on their phone opts into `publishing`, and a member who does
 * not is unaffected. The class name carries `Publish` on purpose: that is what puts it in the
 * `publishing` preference category.
 *
 * Step S-3.4: `$storiesByHand` is how many of those are assisted Stories due today. The template's
 * variables are fixed, so the count is folded into `post_summary` ("3 posts, 1 story to post by
 * hand today").
 *
 * Idempotency is the sweep's, not this notification's — `post_targets.reminder_sent_at` is stamped
 * in the same transaction, so a second run of the command sends nothing.
 */
class UpcomingPublishReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $count,
        private readonly ?string $firstCaption,
        private readonly ?CarbonInterface $soonest,
        private readonly int $storiesByHand = 0,
    ) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return WhatsAppRouting::with([], $notifiable, static::class);
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::PostScheduledReminder->fill([
            'post_summary' => $this->summary(),
            'going_out_at' => $this->soonest?->diffForHumans() ?? 'soon',
            'url' => route('calendar'),
        ]);
    }

    /**
     * "Our autumn menu is here" for one post, "3 posts" for several; and when some are Stories to
     * post by hand today, "2 posts, 1 story to post by hand today". Always within 60 characters.
     */
    private function summary(): string
    {
        if ($this->storiesByHand === 0) {
            return $this->count === 1
                ? Str::limit((string) ($this->firstCaption ?? 'a post'), 60, '')
                : $this->count.' posts';
        }

        $stories = $this->storiesByHand.' '.Str::plural('story', $this->storiesByHand).' to post by hand today';
        $others = $this->count - $this->storiesByHand;

        return $others > 0
            ? Str::limit($others.' '.Str::plural('post', $others).', '.$stories, 60, '')
            : $stories;
    }

    /**
     * Nothing is stored in the bell — but a notification must still answer this, and the channel
     * reads it for the generic template if a template is ever missing.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Posts going out soon',
            'body' => $this->count.' scheduled '.Str::plural('post', $this->count).' coming up',
            'url' => route('calendar'),
        ];
    }
}
