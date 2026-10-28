<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdatePlatformBillingRequest;
use App\Models\Organization;
use App\Services\Platform\PlatformSettings;
use App\Support\Plans\PlanCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-2.6 — platform-wide super-admin settings. Today: the global Stripe billing switch (the
 * master for self-serve checkout across every org) plus a quick plan-distribution overview.
 */
class PlatformSettingController extends Controller
{
    public function edit(PlatformSettings $settings, PlanCatalog $catalog): Response
    {
        $distribution = [];
        foreach ($catalog->all() as $plan) {
            $distribution[] = [
                'key' => $plan->key,
                'name' => $plan->name(),
                'segment' => $plan->segment(),
                'count' => Organization::query()->where('plan', $plan->key)->count(),
            ];
        }

        // Orgs with no explicit plan (resolving to their type default).
        $distribution[] = [
            'key' => '__default__',
            'name' => 'On the type default',
            'segment' => 'mixed',
            'count' => Organization::query()->whereNull('plan')->count(),
        ];

        return Inertia::render('super-admin/settings', [
            'billingEnabled' => $settings->billingEnabled(),
            'planDistribution' => $distribution,
        ]);
    }

    public function updateBilling(UpdatePlatformBillingRequest $request, PlatformSettings $settings): RedirectResponse
    {
        $enabled = (bool) $request->validated('enabled');

        $settings->setBillingEnabled($enabled);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled
                ? __('Stripe billing is on for the platform.')
                : __('Stripe billing is off for the platform.'),
        ]);

        return to_route('super-admin.settings.edit');
    }
}
