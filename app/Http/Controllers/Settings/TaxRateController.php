<?php

namespace App\Http\Controllers\Settings;

use App\Actions\TaxRates\ArchiveTaxRateAction;
use App\Actions\TaxRates\CreateTaxRateAction;
use App\Actions\TaxRates\UpdateTaxRateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreTaxRateRequest;
use App\Http\Requests\Settings\UpdateTaxRateRequest;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Step M-4.1 — the organization's tax-rate list, managed from Settings > Organization (Owner-only,
 * via `TaxRatePolicy`). Thin: each method validates, calls a one-job Action, and redirects back.
 */
class TaxRateController extends Controller
{
    public function store(StoreTaxRateRequest $request, CreateTaxRateAction $action): RedirectResponse
    {
        $action($request->user()->organization, $request->validated());

        $this->toast('Tax rate added.');

        return to_route('organization.edit');
    }

    public function update(UpdateTaxRateRequest $request, TaxRate $taxRate, UpdateTaxRateAction $action): RedirectResponse
    {
        $action($taxRate, $request->validated());

        $this->toast('Tax rate updated.');

        return to_route('organization.edit');
    }

    public function archive(TaxRate $taxRate, ArchiveTaxRateAction $action): RedirectResponse
    {
        $this->authorize('update', $taxRate);

        $restore = $taxRate->isArchived();

        $action($taxRate, $restore);

        $this->toast($restore ? 'Tax rate restored.' : 'Tax rate archived.');

        return to_route('organization.edit');
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
