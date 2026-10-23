<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Organizations\TestUploadPostConnectionAction;
use App\Actions\Organizations\UpdateOrganizationSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationSettingsUpdateRequest;
use App\Models\TaxRate;
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
                'billing_details' => $organization->billing_details,
                'payment_instructions' => $organization->payment_instructions,
                'tax_registration_number' => $organization->tax_registration_number,
                'tax_registration_label' => $organization->tax_registration_label,
                'invoice_payment_terms_days' => $organization->invoice_payment_terms_days,
                // Step M-6.5 (Phase 9): the approval SLA window in hours (null = no SLA).
                'approval_sla_hours' => $organization->approval_sla_hours,
                'has_upload_post_key' => filled($organization->upload_post_key),
                'has_upload_post_webhook_secret' => filled($organization->upload_post_webhook_secret),
                'has_xai_key' => filled($organization->xai_key),
                // Step I-1.6: enough to recognise which key is stored, never the key itself.
                'upload_post_key_last4' => self::lastFour($organization->upload_post_key),
                'upload_post_webhook_secret_last4' => self::lastFour($organization->upload_post_webhook_secret),
                'xai_key_last4' => self::lastFour($organization->xai_key),
            ],
            'currencies' => CurrencyOptions::options(),
            // Step M-4.1: the org's tax rates, active first then archived, for the rates manager.
            'tax_rates' => $organization->taxRates()
                ->orderBy('archived_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($rate) => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'rate' => (string) $rate->rate,
                    'is_archived' => $rate->isArchived(),
                ]),
            'can_manage_tax_rates' => $request->user()->can('manage', TaxRate::class),
        ]);
    }

    /**
     * Step I-1.6 — "Test connection": does Upload-Post accept the stored key?
     */
    public function testUploadPost(Request $request, TestUploadPostConnectionAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $ok = $action($organization, $request->user());

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('Upload-Post accepted your key. You’re connected.')]
            : ['type' => 'error', 'message' => __('Upload-Post didn’t accept the key. Check it and try again.')]);

        return to_route('organization.edit');
    }

    private static function lastFour(?string $secret): ?string
    {
        return filled($secret) && mb_strlen((string) $secret) > 8 ? mb_substr((string) $secret, -4) : null;
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
