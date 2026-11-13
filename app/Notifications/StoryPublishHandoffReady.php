<?php

namespace App\Notifications;

use App\Models\ClientContact;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Services\Branding\BrandingResolver;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step S-3.1 — "Time to post.": an assisted Story is due, and this is the person who posts it.
 *
 * Sent by the handoff sweep (`ProcessStoryHandoffsAction` → `NotifyStoryPosterAction`) at the
 * Story's own time, once more as a nudge if nobody has shared it, and with a "this was due at…"
 * line when the sweep ran late. The class name carries `Publish` on purpose: that is what puts it
 * in the `publishing` preference category.
 *
 * Who gets what:
 *
 * - a **team member** gets the bell, an email, and WhatsApp if they opted in. Unlike a publish
 *   failure, agencies get the email too: this is "post this now", and the bell alone is too quiet;
 * - a **client's email address** (an on-demand route) gets the email;
 * - a **client contact** who consented to WhatsApp gets WhatsApp.
 *
 * The handoff link carries a token, so it only ever travels in the email, the WhatsApp message and
 * the push. **The bell never stores it**: a signed-in member's bell item points at the
 * authenticated route (`posts.story-handoff.open`), which issues a fresh link when it is opened.
 * The queued job is encrypted for the same reason — a token must not sit readable in a queue or a
 * failed-jobs table.
 */
class StoryPublishHandoffReady extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $handoffId,
        private readonly string $url,
        public readonly bool $late = false,
        public readonly bool $nudge = false,
    ) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL, 'whatsapp' => Queues::WHATSAPP];
    }

    /**
     * Checked on the worker, just before each channel sends: a reminder that was queued a moment
     * before the Story was confirmed, recalled or retried says nothing.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $handoff = $this->handoff();

        return $handoff !== null && $handoff->isOpen() && $handoff->isCurrent();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = match (true) {
            // The email always goes: being asked to post something is not a notification a
            // muted bell should be able to swallow.
            $notifiable instanceof User => NotificationPreferences::wantsInApp($notifiable, static::class)
                ? ['database', 'mail']
                : ['mail'],
            // A client contact is not a login and has no bell; WhatsApp is the channel it takes.
            // Its email address, when it is on the review list, is sent to on demand.
            $notifiable instanceof ClientContact => [],
            default => ['mail'],
        };

        return WhatsAppRouting::with($channels, $notifiable, static::class);
    }

    /**
     * What the bell stores. No token: a member opens the handoff through the signed-in route.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $handoff = $this->handoff();

        return [
            'title' => $this->title(),
            'body' => $this->body($handoff),
            'url' => $handoff !== null
                ? route('posts.story-handoff.open', ['post' => $handoff->post_id, 'target' => $handoff->post_target_id])
                : route('needs-attention'),
            'post_id' => $handoff?->post_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $handoff = $this->handoff();
        $target = $handoff?->target;
        $account = $target?->socialAccount;
        $organization = $handoff?->post?->organization;
        $resolver = app(BrandingResolver::class);

        // A client reads this, so it carries the agency's branding; the team's copy is Alima's.
        $branding = $resolver->for($notifiable instanceof User ? null : $organization);

        $mail = new MailMessage;

        // Same rules as `BrandedForClient`: a white-label agency's name and reply-to, but always
        // our own verified sending address.
        if ($branding->isWhiteLabel) {
            $mail->from((string) config('mail.from.address'), $branding->senderName ?? $branding->name);
        }

        if ($branding->replyTo !== null) {
            $mail->replyTo($branding->replyTo, $branding->senderName ?? $branding->name);
        }

        return $mail
            ->subject($this->nudge
                ? __('Still to post: the story for :account', ['account' => $account->handle ?? __('your account')])
                : __('Time to post: the story for :account', ['account' => $account->handle ?? __('your account')]))
            ->view('emails.story-handoff-ready', [
                'branding' => $branding,
                'brandLogoUrl' => $notifiable instanceof User ? null : $resolver->mailLogoUrl($organization),
                'title' => $this->title(),
                'lead' => $this->body($handoff),
                'handle' => $account?->handle,
                'clientName' => $handoff?->client?->name,
                'dueLabel' => $this->dueLabel($handoff),
                'late' => $this->late,
                'extras' => $target?->storyExtras()->checklist() ?? [],
                'placementNote' => $target?->storyExtras()->placementNote,
                'url' => $this->url,
            ]);
    }

    /**
     * Step S-3.3 — the dedicated template once it has been approved for this account; until then
     * the generic one, which is true of anything.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        $handoff = $this->handoff();

        if (MessageTemplate::StoryReadyToPost->contentSid() !== null) {
            return MessageTemplate::StoryReadyToPost->fill([
                'account' => $handoff?->target?->socialAccount->handle ?? 'your account',
                'due_at' => $this->dueLabel($handoff) ?? 'now',
                'url' => $this->url,
            ]);
        }

        return MessageTemplate::Notification->fill([
            'title' => rtrim($this->title(), '.'),
            'body' => rtrim($this->body($handoff), '.'),
            'url' => $this->url,
        ]);
    }

    private function title(): string
    {
        return $this->nudge ? __('Still to post.') : __('Time to post.');
    }

    private function body(?StoryHandoff $handoff): string
    {
        $handle = $handoff?->target?->socialAccount?->handle;
        $story = $handle !== null ? __(':handle’s story', ['handle' => $handle]) : __('A story');

        if ($this->late && ! $this->nudge) {
            return __(':story was due at :time.', ['story' => $story, 'time' => $this->dueTime($handoff) ?? __('its time')]);
        }

        return $this->nudge
            ? __(':story is still waiting to be posted.', ['story' => $story])
            : __(':story is ready.', ['story' => $story]);
    }

    /**
     * The due time in the channel's own timezone — the one the schedule was set in.
     */
    private function dueAt(?StoryHandoff $handoff): ?CarbonInterface
    {
        if ($handoff === null) {
            return null;
        }

        $timezone = $handoff->target?->socialAccount->timezone ?? config('app.timezone');

        return $handoff->due_at->copy()->setTimezone((string) $timezone);
    }

    private function dueTime(?StoryHandoff $handoff): ?string
    {
        return $this->dueAt($handoff)?->format('H:i');
    }

    private function dueLabel(?StoryHandoff $handoff): ?string
    {
        $dueAt = $this->dueAt($handoff);

        return $dueAt === null ? null : $dueAt->format('D j M, H:i').' ('.$dueAt->getTimezone()->getName().')';
    }

    /**
     * Loaded fresh on the worker (lazy loading is disabled app-wide), without access scopes: the
     * worker has nobody signed in, and a synchronous send must not depend on who is.
     */
    private function handoff(): ?StoryHandoff
    {
        return StoryHandoff::query()
            ->withoutGlobalScopes()
            ->with(['target.socialAccount', 'post.organization', 'client'])
            ->find($this->handoffId);
    }
}
