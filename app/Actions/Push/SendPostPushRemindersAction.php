<?php

namespace App\Actions\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Step B-7.4 — the "your post goes out in ~30 minutes" push. Finds every business workspace's
 * scheduled target whose publish time falls inside the lead window and that hasn't been pushed yet
 * (`push_reminder_sent_at` null), and sends a reminder to every push subscription of that
 * workspace's users. The stamp makes the every-5-minutes sweep idempotent; a `Gone` subscription
 * (the browser unsubscribed) is pruned so it's never tried again.
 *
 * Only runs when push is configured, and never throws for a single dead subscription — one bad
 * endpoint can't sink a whole sweep.
 */
class SendPostPushRemindersAction
{
    public function __construct(private readonly PushNotifier $notifier) {}

    /**
     * @return int the number of targets reminded about
     */
    public function __invoke(): int
    {
        if (! $this->notifier->isConfigured()) {
            return 0;
        }

        $now = Carbon::now();
        $lead = max(1, (int) config('push.reminder_lead_minutes', 30));
        $window = max(1, (int) config('push.reminder_window_minutes', 10));

        // The target fires once it's within `lead` of publishing. The window's lower bound keeps a
        // target that's already overdue (e.g. the publisher is running late) from re-pushing.
        $from = $now->clone()->subMinutes($window);
        $until = $now->clone()->addMinutes($lead);

        $targets = PostTarget::query()
            ->whereNull('push_reminder_sent_at')
            ->whereNotNull('scheduled_at_utc')
            ->whereBetween('scheduled_at_utc', [$from, $until])
            ->whereIn('post_id', Post::query()->where('status', PostStatus::Scheduled)->forActiveClients()->select('id'))
            ->whereHas('post.organization', fn ($q) => $q->whereNull('suspended_at')->where('type', 'business'))
            ->with(['post.organization', 'socialAccount'])
            ->get();

        if ($targets->isEmpty()) {
            return 0;
        }

        // One subscription lookup per org, reused across that org's targets.
        $subscriptionsByOrg = [];
        $reminded = 0;

        foreach ($targets as $target) {
            /** @var PostTarget $target */
            $orgId = $target->post->org_id;

            $subscriptionsByOrg[$orgId] ??= PushSubscription::query()
                ->whereIn('user_id', $target->post->organization->users()->select('id'))
                ->get();

            $message = $this->messageFor($target);

            foreach ($subscriptionsByOrg[$orgId] as $subscription) {
                $result = $this->notifier->send($subscription, $message);

                if ($result === PushDeliveryResult::Gone) {
                    $subscription->delete();
                }
            }

            $target->forceFill(['push_reminder_sent_at' => Carbon::now()])->save();
            $reminded++;
        }

        Log::info('Post push reminders sent', [
            'target_count' => $reminded,
            'org_count' => count($subscriptionsByOrg),
        ]);

        return $reminded;
    }

    private function messageFor(PostTarget $target): PushMessage
    {
        $platform = $target->socialAccount?->platform?->label();
        $when = $target->scheduled_local_time ?? $target->scheduled_at_utc?->format('H:i');

        return new PushMessage(
            title: __('Going out soon'),
            body: $platform !== null
                ? __('Your :platform post goes out at :time.', ['platform' => $platform, 'time' => $when])
                : __('Your post goes out at :time.', ['time' => $when]),
            url: route('posts.edit', $target->post_id),
            tag: 'post-reminder-'.$target->id,
        );
    }
}
