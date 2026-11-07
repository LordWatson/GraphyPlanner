<?php

namespace App\Actions\SuperAdmin;

use App\Enums\Feature;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Support\Plans\PlanCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-7.1 (closes CG-9.1 / B-12.4) — the handful of figures that say whether the product is
 * working as a business, as opposed to whether the software runs.
 *
 * Every figure is a plain aggregate over tables the app already writes; there is no new model and
 * nothing stored. Two rules it keeps:
 *
 *  - **Store GMV comes from `orders`, never from a Stripe balance.** Creators are paid directly
 *    into their own connected accounts (Principle 3), so our Stripe balance has never seen that
 *    money and asking it would give a wrong answer with total confidence.
 *  - **A denominator of zero is `null`, not 0%.** A new install with no trials has no conversion
 *    rate; printing 0% would read as "nobody converts".
 *
 * Kept out of `GetPlatformAnalyticsSummaryAction` on purpose: that action is already long, these
 * are lifetime-to-date business figures rather than range-filtered platform aggregates, and the two
 * answer different questions.
 */
class GetFunnelMetricsAction
{
    public function __construct(private readonly PlanCatalog $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $orgIds = Organization::query()->excludingPreview()->pluck('id');

        $funnel = [
            'conversion' => $this->conversion($orgIds),
            'time_to_first_post' => $this->timeToFirstPost($orgIds),
            'bio_pages' => $this->bioPages($orgIds),
            'store_gmv' => $this->storeGmv($orgIds),
            'churn_by_plan' => $this->churnByPlan($orgIds),
            'agency_utilisation' => $this->agencyUtilisation($orgIds),
        ];

        Log::info('Funnel metrics built', ['organizations' => $orgIds->count()]);

        return $funnel;
    }

    /**
     * Trial start → paid. A trial is an org that has ever had `trial_ends_at` set; paid is one with
     * a subscription that has ever been active, which survives the trial ending.
     *
     * @param  Collection<int, int>  $orgIds
     * @return array{trials: int, paid: int, rate: float|null}
     */
    private function conversion($orgIds): array
    {
        $trials = Organization::query()->whereIn('id', $orgIds)->whereNotNull('trial_ends_at')->count();

        $paid = DB::table('subscriptions')
            ->whereIn('organization_id', $orgIds)
            ->whereIn('stripe_status', ['active', 'past_due', 'trialing'])
            ->distinct()
            ->count('organization_id');

        return [
            'trials' => $trials,
            'paid' => $paid,
            'rate' => $trials === 0 ? null : round($paid / $trials * 100, 1),
        ];
    }

    /**
     * How long a new workspace takes to get something out of the door — the single best early
     * signal that onboarding works. Median rather than mean, because one org that took a year
     * would drag an average into uselessness.
     *
     * @param  Collection<int, int>  $orgIds
     * @return array{median_hours: float|null, published: int, total: int}
     */
    private function timeToFirstPost($orgIds): array
    {
        $firsts = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('organizations', 'organizations.id', '=', 'posts.org_id')
            ->whereIn('posts.org_id', $orgIds)
            ->where('post_targets.status', PostTargetStatus::Published->value)
            ->whereNotNull('post_targets.published_at')
            ->groupBy('posts.org_id', 'organizations.created_at')
            ->get([
                'posts.org_id',
                'organizations.created_at',
                DB::raw('min(post_targets.published_at) as first_published_at'),
            ]);

        $hours = $firsts
            ->map(fn (object $row) => (strtotime((string) $row->first_published_at) - strtotime((string) $row->created_at)) / 3600)
            ->filter(fn (float $value) => $value >= 0)
            ->sort()
            ->values();

        return [
            'median_hours' => $hours->isEmpty() ? null : round((float) $hours->median(), 1),
            'published' => $hours->count(),
            'total' => $orgIds->count(),
        ];
    }

    /**
     * How many workspaces got as far as putting a bio page live — the CG-3 adoption figure.
     *
     * @param  Collection<int, int>  $orgIds
     * @return array{published: int, total: int, rate: float|null}
     */
    private function bioPages($orgIds): array
    {
        $businesses = Organization::query()
            ->whereIn('id', $orgIds)
            ->where('type', OrganizationType::Business)
            ->pluck('id');

        $published = DB::table('bio_pages')
            ->whereIn('org_id', $businesses)
            ->whereNotNull('published_at')
            ->distinct()
            ->count('org_id');

        return [
            'published' => $published,
            'total' => $businesses->count(),
            'rate' => $businesses->isEmpty() ? null : round($published / $businesses->count() * 100, 1),
        ];
    }

    /**
     * What creators have actually sold, per currency — **from our own `orders`**, because the money
     * never passes through our Stripe account.
     *
     * @param  Collection<int, int>  $orgIds
     * @return array{orders: int, totals: list<array{currency: string, amount_minor: int}>}
     */
    private function storeGmv($orgIds): array
    {
        $rows = DB::table('orders')
            ->whereIn('org_id', $orgIds)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Fulfilled->value])
            ->groupBy('currency')
            ->get(['currency', DB::raw('count(*) as orders'), DB::raw('sum(amount_minor) as amount_minor')]);

        return [
            'orders' => (int) $rows->sum('orders'),
            // Never summed across currencies, like every other money figure here.
            'totals' => array_values($rows->map(fn (object $row) => [
                'currency' => (string) $row->currency,
                'amount_minor' => (int) $row->amount_minor,
            ])->all()),
        ];
    }

    /**
     * Cancellations per plan. `ends_at` in the past is a subscription that has actually lapsed, not
     * one merely scheduled to.
     *
     * @param  Collection<int, int>  $orgIds
     * @return list<array{plan: string, active: int, churned: int, rate: float|null}>
     */
    private function churnByPlan($orgIds): array
    {
        $rows = [];

        foreach ($this->plans->all() as $plan) {
            $active = Organization::query()->whereIn('id', $orgIds)->where('plan', $plan->key)->count();

            $churned = DB::table('subscriptions')
                ->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
                ->whereIn('subscriptions.organization_id', $orgIds)
                ->where('organizations.plan', $plan->key)
                ->whereNotNull('subscriptions.ends_at')
                ->where('subscriptions.ends_at', '<', now())
                ->distinct()
                ->count('subscriptions.organization_id');

            if ($active === 0 && $churned === 0) {
                continue;
            }

            $rows[] = [
                'plan' => $plan->key,
                'active' => $active,
                'churned' => $churned,
                'rate' => $active + $churned === 0 ? null : round($churned / ($active + $churned) * 100, 1),
            ];
        }

        return $rows;
    }

    /**
     * Step GTM-7.1's new figure: how close agencies are to the brand and seat caps GTM-0.1 set, so
     * the next price change is argued from evidence rather than a feeling. An agency at 90% of its
     * brands is an upgrade waiting to happen; a whole tier averaging 20% is a cap set too high.
     *
     * @param  Collection<int, int>  $orgIds
     * @return list<array{plan: string, organizations: int, brand_limit: int|null, seat_limit: int|null, brands_used: float, seats_used: float, brand_utilisation: float|null, seat_utilisation: float|null, at_brand_cap: int}>
     */
    private function agencyUtilisation($orgIds): array
    {
        $rows = [];

        foreach ($this->plans->all() as $plan) {
            if (! $plan->isAgency()) {
                continue;
            }

            $organizations = Organization::query()
                ->whereIn('id', $orgIds)
                ->where('plan', $plan->key)
                ->withCount([
                    'clients as brand_count' => fn ($query) => $query->whereNull('deleted_at'),
                    'users as seat_count' => fn ($query) => $query->where('role', '!=', Role::ClientReviewer->value),
                ])
                ->get();

            if ($organizations->isEmpty()) {
                continue;
            }

            $brandLimit = $plan->limit(Feature::Brands);
            $seatLimit = $plan->limit(Feature::TeamSeats);
            $brands = (float) $organizations->avg('brand_count');
            $seats = (float) $organizations->avg('seat_count');

            $rows[] = [
                'plan' => $plan->key,
                'organizations' => $organizations->count(),
                'brand_limit' => $brandLimit,
                'seat_limit' => $seatLimit,
                'brands_used' => round($brands, 1),
                'seats_used' => round($seats, 1),
                // Null on an unlimited tier: a percentage of unlimited is not a number.
                'brand_utilisation' => $brandLimit === null || $brandLimit === 0 ? null : round($brands / $brandLimit * 100, 1),
                'seat_utilisation' => $seatLimit === null || $seatLimit === 0 ? null : round($seats / $seatLimit * 100, 1),
                'at_brand_cap' => $brandLimit === null
                    ? 0
                    : $organizations->filter(fn (Organization $org) => (int) $org->brand_count >= $brandLimit)->count(),
            ];
        }

        return $rows;
    }
}
