<?php

namespace App\Actions\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Enums\OrganizationType;
use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\PostTarget;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-1.3 — the daily "nothing scheduled tomorrow" nudge for active business/creator workspaces.
 * For each onboarded business org whose brand has a connected account but nothing scheduled for
 * tomorrow (in the org's timezone), push a reminder to plan one. Idempotent per org per local day
 * (a cache guard), so a re-run the same day won't re-nudge; never throws for a dead endpoint and
 * prunes a `Gone` subscription.
 */
class SendNothingScheduledTomorrowPushAction
{
    public function __construct(private readonly PushNotifier $notifier) {}

    /**
     * @return int the number of workspaces nudged
     */
    public function __invoke(): int
    {
        if (! $this->notifier->isConfigured()) {
            return 0;
        }

        $nudged = 0;

        Organization::query()
            ->where('type', OrganizationType::Business)
            ->whereNull('suspended_at')
            ->whereNotNull('onboarding_completed_at')
            ->with('primaryBrand')
            ->each(function (Organization $organization) use (&$nudged): void {
                $brand = $organization->primaryBrand;

                // Only nudge a set-up brand: one with a connected account actually posting.
                if ($brand === null || ! $brand->socialAccounts()->exists()) {
                    return;
                }

                $timezone = $organization->default_timezone ?? 'UTC';
                $start = Carbon::now($timezone)->addDay()->startOfDay();
                $end = $start->clone()->endOfDay();

                $hasScheduled = PostTarget::query()
                    ->whereNotNull('scheduled_at_utc')
                    ->whereBetween('scheduled_at_utc', [$start->clone()->utc(), $end->clone()->utc()])
                    ->whereHas('post', fn (Builder $post) => $post
                        ->where('org_id', $organization->id)
                        ->where('client_id', $brand->id)
                        ->where('status', PostStatus::Scheduled))
                    ->exists();

                if ($hasScheduled) {
                    return;
                }

                // Idempotent per org per local day — a re-run the same day is a no-op.
                $guard = 'push:empty-tomorrow:'.$organization->id.':'.$start->toDateString();

                if (! Cache::add($guard, true, now()->addHours(26))) {
                    return;
                }

                $message = new PushMessage(
                    title: __('Nothing lined up for tomorrow'),
                    body: __('You have no posts scheduled for tomorrow — want to plan one?'),
                    url: route('business.today'),
                    tag: 'empty-tomorrow-'.$start->toDateString(),
                );

                $subscriptions = PushSubscription::query()
                    ->whereIn('user_id', $organization->users()->select('id'))
                    ->get();

                foreach ($subscriptions as $subscription) {
                    if ($this->notifier->send($subscription, $message) === PushDeliveryResult::Gone) {
                        $subscription->delete();
                    }
                }

                $nudged++;
            });

        Log::info('Nothing-scheduled-tomorrow pushes sent', ['workspace_count' => $nudged]);

        return $nudged;
    }
}
