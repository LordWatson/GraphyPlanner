<?php

namespace App\Actions\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Step S-3.2 — the phone buzzes: "Time to post. @handle's story is ready."
 *
 * Email is too slow for "post this now", so the team member who posts an assisted Story also gets
 * a web push on every browser they opted in from. The nudge reuses the first notification's `tag`,
 * so it replaces it on the lock screen instead of stacking a second one.
 *
 * Unlike the business-only "goes out soon" pushes, this one goes to **agency** members too — it is
 * the reason the opt-in was opened to them. A client recipient gets no push in this version.
 * A no-op when push is not configured; never throws for a dead endpoint, and prunes a `Gone` one.
 */
class SendStoryHandoffPushAction
{
    public function __construct(private readonly PushNotifier $notifier) {}

    /**
     * @param  string  $url  the handoff link (it carries the token, and is never logged)
     * @param  Collection<int, User>  $users  the team members being told (none for a client handoff)
     * @return int the number of subscriptions it was delivered to
     */
    public function __invoke(StoryHandoff $handoff, string $url, bool $nudge, Collection $users): int
    {
        if (! $this->notifier->isConfigured()) {
            return 0;
        }

        if ($users->isEmpty()) {
            return 0;
        }

        $handoff->loadMissing('target.socialAccount');
        $handle = $handoff->target?->socialAccount?->handle;
        $story = $handle !== null ? __(':handle’s story', ['handle' => $handle]) : __('A story');

        $message = new PushMessage(
            title: $nudge ? __('Still to post.') : __('Time to post.'),
            body: $nudge
                ? __(':story is still waiting to be posted.', ['story' => $story])
                : __(':story is ready.', ['story' => $story]),
            url: $url,
            tag: 'story-handoff-'.$handoff->id,
        );

        $subscriptions = PushSubscription::query()->whereIn('user_id', $users->pluck('id'))->get();
        $delivered = 0;

        foreach ($subscriptions as $subscription) {
            $result = $this->notifier->send($subscription, $message);

            if ($result === PushDeliveryResult::Gone) {
                $subscription->delete();
            } elseif ($result === PushDeliveryResult::Delivered) {
                $delivered++;
            }
        }

        Log::info('Story handoff push sent', [
            'org_id' => $handoff->org_id,
            'post_id' => $handoff->post_id,
            'story_handoff_id' => $handoff->id,
            'nudge' => $nudge,
            'subscriptions' => $subscriptions->count(),
            'delivered' => $delivered,
        ]);

        return $delivered;
    }
}
