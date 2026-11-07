<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\ReferralPartner;
use Illuminate\Support\Facades\DB;

/**
 * Step GTM-7.3 — the referral programme as a super admin needs to see it: who sent whom, what has
 * been paid, and what is still owed.
 *
 * **What is owed is derived, never stored.** `referral_payouts` records money actually sent; the
 * balance is subscription revenue attributed to the partner, times their commission, minus what
 * has been paid. Storing the balance would mean two places that could disagree about the same
 * money, and the one that is wrong is always the stored one.
 *
 * Revenue is read from Cashier's `subscriptions` — the attributed organization *is* the billable —
 * and expressed as the count of currently-paying orgs rather than a cash figure, because this
 * install has no subscription payment history to sum yet (no live Stripe prices). That is stated on
 * the page rather than papered over with a confident zero.
 */
class GetReferralPartnersAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $partners = ReferralPartner::query()
            ->withCount([
                'organizations as referred_count',
                'organizations as paying_count' => fn ($query) => $query->whereHas(
                    'subscriptions',
                    fn ($inner) => $inner->whereIn('stripe_status', ['active', 'past_due', 'trialing']),
                ),
            ])
            ->orderByDesc('referred_count')
            ->orderBy('name')
            ->get();

        $paid = DB::table('referral_payouts')
            ->whereNotNull('paid_at')
            ->groupBy('referral_partner_id', 'currency')
            ->get(['referral_partner_id', 'currency', DB::raw('sum(amount_minor) as amount_minor')]);

        return [
            'partners' => array_values($partners->map(fn (ReferralPartner $partner) => [
                'id' => $partner->id,
                'code' => $partner->code,
                'name' => $partner->name,
                'email' => $partner->email,
                'commission_percent' => $partner->commission_percent,
                'active' => $partner->isActive(),
                'referred_count' => (int) $partner->referred_count,
                'paying_count' => (int) $partner->paying_count,
                // Never summed across currencies, like every other money figure in the console.
                'paid' => array_values($paid
                    ->where('referral_partner_id', $partner->id)
                    ->map(fn (object $row) => [
                        'currency' => (string) $row->currency,
                        'amount_minor' => (int) $row->amount_minor,
                    ])
                    ->all()),
            ])->all()),
            'unattributed' => Organization::query()->excludingPreview()->whereNull('referral_partner_id')->count(),
            'default_commission_percent' => (int) config('referrals.default_commission_percent', 20),
        ];
    }
}
