<?php

namespace App\Http\Controllers;

use App\Support\Plans\Plan;
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
     * The plans shown publicly, both currencies, in config order.
     *
     * @return array<string, mixed>
     */
    private function pricingData(): array
    {
        return [
            'plans' => array_map(
                fn (Plan $plan) => $plan->toArray(['gbp', 'usd']),
                $this->catalog->public(),
            ),
            'currencies' => (array) config('plans.currencies'),
            'annual_discount_label' => (string) config('plans.annual_discount_label'),
        ];
    }
}
