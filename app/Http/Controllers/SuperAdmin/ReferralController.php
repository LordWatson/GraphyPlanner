<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetReferralPartnersAction;
use App\Http\Controllers\Controller;
use App\Models\ReferralPartner;
use App\Models\ReferralPayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step GTM-7.3 — the referral programme, super-admin side: create a partner, see who they sent,
 * and record a payout.
 *
 * A payout here is a **record of money already sent**, not an instruction to send any: there is no
 * payment integration behind it, deliberately, because paying partners is a bank transfer and a
 * conversation rather than something to automate before the first one exists.
 */
class ReferralController extends Controller
{
    public function index(GetReferralPartnersAction $partners): Response
    {
        return Inertia::render('super-admin/referrals/index', $partners());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email:filter', 'max:255'],
            'commission_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ], [
            'code.regex' => __('A code is letters, numbers, dots, dashes and underscores.'),
        ]);

        $code = ReferralPartner::normaliseCode($validated['code']);

        if (ReferralPartner::query()->where('code', $code)->exists()) {
            return back()->withErrors(['code' => __('That code is taken.')]);
        }

        ReferralPartner::create([...$validated, 'code' => $code]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Partner added.')]);

        return back();
    }

    public function storePayout(Request $request, ReferralPartner $partner): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(array_map('strtoupper', array_keys((array) config('plans.currencies'))))],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        ReferralPayout::create([
            ...$validated,
            'referral_partner_id' => $partner->id,
            // Recorded as paid: this endpoint exists to write down a transfer that has happened.
            'paid_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payout recorded.')]);

        return back();
    }

    /**
     * Ending a partnership keeps every attribution: the customers they already sent are still
     * theirs, and their code simply stops tracking new ones.
     */
    public function end(ReferralPartner $partner): RedirectResponse
    {
        $partner->update(['ended_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Partnership ended. Their existing referrals are untouched.')]);

        return back();
    }
}
