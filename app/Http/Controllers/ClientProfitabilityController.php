<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientProfitabilityService;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Client profitability" — the roster ordered by margin, worst first.
 *
 * Owner-only, and internal by construction: this is the agency's own view of its own economics and
 * must never reach a client-facing report. Authorised against `viewBilling` on the organization,
 * the same gate that hides retainer figures from non-billing roles on the roster — a margin is
 * strictly more sensitive than the retainer it is computed from.
 */
class ClientProfitabilityController extends Controller
{
    public function __invoke(Request $request, ClientProfitabilityService $service): Response
    {
        $organization = $request->user()->organization;

        // `viewBilling` lives on `ClientPolicy`, which is where the roster already reads it from
        // to decide whether retainer figures are shown at all. A margin is strictly more sensitive
        // than the retainer it is computed from, so it uses the same gate rather than a new one.
        $this->authorize('viewBilling', new Client(['org_id' => $organization->id]));

        $months = max(1, min(12, (int) $request->integer('months', 3)));
        $rows = $service->forOrganization($organization, $months);
        $currency = (string) $organization->default_currency;

        return Inertia::render('clients/profitability', [
            'months' => $months,
            'currency' => $currency,
            'rateConfigured' => ClientProfitabilityService::hourlyRateMinor($organization) > 0,
            'clients' => array_map(fn (array $row) => [
                ...$row,
                // Formatted here so one money rule applies everywhere, rather than the page
                // inventing a second one.
                'revenue' => MoneyFormatter::format($row['revenue_minor'] / 100, $currency),
                'cost' => $row['cost_minor'] === null ? null : MoneyFormatter::format($row['cost_minor'] / 100, $currency),
                'margin' => $row['margin_minor'] === null ? null : MoneyFormatter::format($row['margin_minor'] / 100, $currency),
            ], $rows),
        ]);
    }
}
