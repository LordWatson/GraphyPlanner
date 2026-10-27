<?php

namespace App\Actions\Push;

use App\Actions\Publishing\ResolvePostPublishOutcomeAction;
use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-1.3 — the "a post failed to publish" push for business/creator workspaces. Dispatched
 * alongside the in-app `PostPublishFailed` bell from {@see ResolvePostPublishOutcomeAction}.
 * Agency orgs keep the email + bell only (they aren't on the phone-first PWA); this nudges a solo
 * creator who isn't watching the dashboard.
 *
 * Only runs when push is configured and the post belongs to a business org; never throws for a dead
 * endpoint, and prunes a `Gone` subscription.
 */
class SendPublishFailurePushAction
{
    public function __construct(private readonly PushNotifier $notifier) {}

    public function __invoke(Post $post): void
    {
        if (! $this->notifier->isConfigured()) {
            return;
        }

        $organization = $post->organization;

        if ($organization === null || ! $organization->isBusiness()) {
            return;
        }

        $message = new PushMessage(
            title: __('Post didn’t go out'),
            body: __('A post failed to publish — take a look and retry.'),
            url: route('posts.edit', $post->id),
            tag: 'publish-failed-'.$post->id,
        );

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $organization->users()->select('id'))
            ->get();

        foreach ($subscriptions as $subscription) {
            if ($this->notifier->send($subscription, $message) === PushDeliveryResult::Gone) {
                $subscription->delete();
            }
        }

        Log::info('Publish-failure push sent', ['post_id' => $post->id, 'org_id' => $post->org_id, 'subscriptions' => $subscriptions->count()]);
    }
}
