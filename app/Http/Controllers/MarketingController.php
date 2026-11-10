<?php

namespace App\Http\Controllers;

use App\Models\ReferralPartner;
use App\Support\Plans\PlanCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-2.7 / B-12.2 — the public segment and pricing pages. The pricing table is generated from
 * `config/plans.php` (via {@see PlanCatalog}) so the marketing figures can never drift from what the
 * app enforces. Guest-accessible.
 */
class MarketingController extends Controller
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function forBusiness(): Response
    {
        return Inertia::render('marketing/for-business', [
            'pricing' => $this->pricingData(),
        ]);
    }

    public function forCreators(): Response
    {
        return Inertia::render('marketing/for-creators', [
            'pricing' => $this->pricingData(),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('marketing/pricing', [
            'pricing' => $this->pricingData(),
        ]);
    }

    /**
     * Step GTM-7.3 — an honest comparison page per competitor, generated from
     * `config/competitors.php` so **every figure on it carries one "last checked" date** and cannot
     * quietly rot. `competitors:review-due` (GTM-7.2) is what nags somebody to move that date.
     *
     * The page states what each rival does **better**, not only what we do. A comparison page that
     * pretends a competitor has no strengths is the kind that gets quoted back at you, and the
     * honest version is the one that converts.
     */
    public function compare(string $rival): Response
    {
        $rivals = (array) config('competitors.rivals', []);

        abort_unless(isset($rivals[$rival]), 404);

        return Inertia::render('marketing/compare', [
            'rival' => ['slug' => $rival, ...$rivals[$rival]],
            'others' => array_map(
                fn (string $slug) => ['slug' => $slug, 'name' => $rivals[$slug]['name']],
                array_values(array_diff(array_keys($rivals), [$rival])),
            ),
            'lastChecked' => (string) config('competitors.last_checked'),
            'pricing' => $this->pricingData(),
        ]);
    }

    /**
     * Step GTM-7.3 — the affiliate programme's public page. Deliberately plain: what the
     * commission is, how attribution works, and how to ask. Nothing self-serve, because the first
     * partners are people we will talk to anyway.
     */
    public function partners(): Response
    {
        return Inertia::render('marketing/partners', [
            'commissionPercent' => (int) config('referrals.default_commission_percent', 20),
            'cookieDays' => ReferralPartner::COOKIE_DAYS,
            'contactEmail' => (string) config('referrals.contact_email', 'partners@alima.com'),
        ]);
    }

    /**
     * Step GTM-7.4 — the agent-access page.
     *
     * The November 2026 review found Buffer, Metricool, Planable and Hootsuite all shipping MCP
     * servers, several on free plans, so **"we have agent access and they don't" is dead as a
     * claim** — and there was no page making it here anyway. What this page claims instead is the
     * part a buyer's security review actually asks about and a single-user connector cannot answer:
     * org-scoped isolation, capability scopes, per-connection rate limits, an audit trail, two
     * master switches and a checked-in security review.
     */
    public function agents(): Response
    {
        return Inertia::render('marketing/agents', [
            'pricing' => $this->pricingData(),
        ]);
    }

    /**
     * The plans shown publicly, both currencies, in config order.
     *
     * @return array<string, mixed>
     */
    private function pricingData(): array
    {
        return $this->catalog->publicPricing(['gbp', 'usd']);
    }
}
