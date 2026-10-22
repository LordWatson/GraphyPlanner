<?php

namespace App\Notifications;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies org users that a post failed to publish to one or more targets — dispatched from
 * `ResolvePostPublishOutcomeAction` whenever the post ends up `failed` or `partially_published`.
 *
 * Step B-5.3: each failed channel carries a plain-language reason and a fix (`PublishFailureReason`),
 * and a business workspace also gets an email so a solo owner never misses a silent failure. The
 * in-app bell notification (every org) is unchanged in shape beyond the added `failures` detail.
 */
class PostPublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

    /**
     * Step I-2.8: bell notifications and mail each run on their own queue.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Queued: the Post is reloaded fresh on the worker, so load what we read (lazy loading is
        // disabled app-wide).
        $this->post->loadMissing(['organization', 'client', 'targets.socialAccount']);

        // Business workspaces also get an email (Step B-5.3, "no failure is silent"); agencies keep
        // the in-app bell notification they've always had.
        return $this->post->organization?->isBusiness()
            ? ['database', 'mail']
            : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->post->loadMissing(['client', 'targets.socialAccount']);
        $partial = $this->post->status === PostStatus::PartiallyPublished;

        return [
            'title' => $partial ? 'Publish partly failed' : 'Publish failed',
            'body' => sprintf(
                $partial ? 'A post for %s went out on some channels but failed on others'
                    : 'A post for %s failed to publish',
                $this->post->client->name,
            ),
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
            // Step B-5.3: the plain-language reason per failed channel, for a richer bell item.
            'failures' => $this->failures(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->post->loadMissing(['client', 'targets.socialAccount']);
        $partial = $this->post->status === PostStatus::PartiallyPublished;
        $brand = $this->post->client->name;

        $mail = (new MailMessage)
            ->subject($partial ? "Some of your post didn't go out" : "Your post didn't go out")
            ->greeting('Heads up.')
            ->line($partial
                ? "A post for {$brand} went out on some channels, but a few didn't."
                : "A post for {$brand} couldn't be published.");

        foreach ($this->failures() as $failure) {
            // "Instagram — needs you to reconnect… Reconnect the account, then retry."
            $mail->line("{$failure['channel']} — {$failure['message']} {$failure['fix']}");
        }

        return $mail
            ->action('Open the post', route('posts.edit', $this->post))
            ->line('You can retry the channels that failed from there.');
    }

    /**
     * The failed channels with a plain-language reason and fix.
     *
     * @return array<int, array{channel: string, platform: string|null, handle: string|null, reason: string, message: string, fix: string}>
     */
    private function failures(): array
    {
        $this->post->loadMissing(['targets.socialAccount', 'client']);

        return $this->post->targets
            ->where('status', PostTargetStatus::Failed)
            ->map(function (PostTarget $target): array {
                $platform = $target->socialAccount?->platform;
                $reason = PublishFailureReason::classify($target->error, $platform);

                return [
                    'channel' => $platform?->label() ?? 'A channel',
                    'platform' => $platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'reason' => $reason->value,
                    'message' => $reason->message($platform),
                    'fix' => $reason->fix($platform),
                ];
            })
            ->values()
            ->all();
    }
}
