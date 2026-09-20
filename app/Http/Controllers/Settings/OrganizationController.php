<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Organizations\UpdateOrganizationSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationSettingsUpdateRequest;
use App\Support\Options\CurrencyOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    /**
     * Show the organization's settings page (Owner-only).
     */
    public function edit(Request $request): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/organization', [
            'organization' => [
                'name' => $organization->name,
                'default_timezone' => $organization->default_timezone,
                'default_currency' => $organization->default_currency,
                'has_upload_post_key' => filled($organization->upload_post_key),
                'has_upload_post_webhook_secret' => filled($organization->upload_post_webhook_secret),
                'has_xai_key' => filled($organization->xai_key),
            ],
            'currencies' => CurrencyOptions::options(),
        ]);
    }

    /**
     * Update the organization's settings.
     */
    public function update(OrganizationSettingsUpdateRequest $request, UpdateOrganizationSettingsAction $action): RedirectResponse
    {
        $action($request->user()->organization, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization settings updated.')]);

        return to_route('organization.edit');
    }
}
