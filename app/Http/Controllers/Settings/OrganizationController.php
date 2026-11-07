<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Organizations\TestUploadPostConnectionAction;
use App\Actions\Organizations\UpdateBrandLogoAction;
use App\Actions\Organizations\UpdateOrganizationSettingsAction;
use App\Contracts\AssetStorage;
use App\Contracts\ChatNotifier;
use App\Contracts\Entitlements;
use App\Contracts\PublishCredentials;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationSettingsUpdateRequest;
use App\Models\TaxRate;
use App\Rules\SafeUpload;
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
    public function edit(Request $request, AssetStorage $storage): Response
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
                // Step 1.5: the publish webhook fails closed, so an organization with no
                // *resolvable* secret has every publish result refused and silently never recorded.
                // Asked through `PublishCredentials` rather than read off the column, so a managed
                // workspace (which verifies with the platform secret and needs none of its own) is
                // not warned about a problem it does not have. This is the page where the person who
                // can fix it is standing.
                'webhook_secret_missing' => app(PublishCredentials::class)->webhookSecretFor($organization) === '',
                'has_chat_webhook_url' => filled($organization->chat_webhook_url),
                'restrict_staff_to_assigned_clients' => (bool) $organization->restrict_staff_to_assigned_clients,
                'has_xai_key' => filled($organization->xai_key),
                // Step I-1.6: enough to recognise which key is stored, never the key itself.
                'upload_post_key_last4' => self::lastFour($organization->upload_post_key),
                'upload_post_webhook_secret_last4' => self::lastFour($organization->upload_post_webhook_secret),
                'xai_key_last4' => self::lastFour($organization->xai_key),
                // Step GTM-3.1: white-label branding. `white_label` says whether it currently
                // reaches a client (the plan gate); the values are the org's either way.
                'brand_accent' => $organization->brand_accent,
                'brand_sender_name' => $organization->brand_sender_name,
                'brand_reply_to' => $organization->brand_reply_to,
                'brand_footer' => $organization->brand_footer,
                'brand_logo_url' => $organization->brand_logo_path === null ? null : $storage->url(
                    (string) $organization->brand_logo_disk,
                    (string) $organization->brand_logo_path,
                ),
            ],
            'white_label' => app(Entitlements::class)->allows($organization, Feature::WhiteLabel),
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
     * Step GTM-3.1 — the white-label logo. Its own endpoint rather than a field on the settings
     * PUT, so that form stays a plain JSON submit (the invoice PDF upload works the same way).
     */
    public function uploadLogo(Request $request, UpdateBrandLogoAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->authorize('update', $organization);

        $extensions = ['png', 'jpg', 'jpeg', 'webp'];

        $validated = $request->validate([
            'brand_logo' => ['required', 'file', 'mimes:'.implode(',', $extensions), 'max:2048', new SafeUpload($extensions)],
        ]);

        $action($organization, $validated['brand_logo']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo saved.')]);

        return back();
    }

    public function removeLogo(Request $request, UpdateBrandLogoAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;
        $this->authorize('update', $organization);

        $action->remove($organization);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo removed.')]);

        return back();
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

    /**
     * Post a message to the organization's chat channel now, so the owner can see it arrive.
     * Nobody trusts a webhook field they cannot test, and a silent integration is one that gets
     * blamed later for the messages it never sent.
     */
    public function testChatWebhook(Request $request, ChatNotifier $chat): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        if (! $chat->isConfiguredFor($organization)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Add an https webhook URL first, then save.')]);

            return to_route('organization.edit');
        }

        $chat->notifyOrganization(
            $organization,
            __('Alima is connected'),
            __('Approvals, change requests and publish failures will appear here.'),
            route('dashboard'),
        );

        // Delivery is best-effort by contract (it never throws), so this says "sent", not
        // "delivered" — the honest claim, and the channel itself is the confirmation.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test message sent. Check the channel.')]);

        return to_route('organization.edit');
    }

    /**
     * Turn team-chat delivery off. Its own endpoint because the settings form keeps a blank
     * credential field rather than clearing it, so removal has to be something the owner asks for.
     */
    public function removeChatWebhook(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $organization->update(['chat_webhook_url' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team chat notifications are off.')]);

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
