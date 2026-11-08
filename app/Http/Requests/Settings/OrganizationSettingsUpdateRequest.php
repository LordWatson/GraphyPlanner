<?php

namespace App\Http\Requests\Settings;

use App\Rules\SendableWhatsAppNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OrganizationSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'brand_accent.regex' => __('Use a six-digit hex colour, like #205557.'),
            'require_two_factor.enrolled' => __('Set up two-factor authentication on your own account first — otherwise turning this on would lock you out.'),
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->organization !== null
            && $this->user()->can('update', $this->user()->organization);
    }

    /**
     * The one rule that cannot be expressed as a validation rule: turning org-wide two-factor on
     * is irreversible from the inside if the person doing it has no second factor, because
     * `EnsureTwoFactorEnrolled` immediately locks them out of settings. So it is refused here
     * rather than left as a support ticket.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('require_two_factor') && ! $this->user()->hasEnabledTwoFactorAuthentication()) {
                    $validator->errors()->add('require_two_factor', $this->messages()['require_two_factor.enrolled']);
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', 'size:3'],
            'billing_details' => ['nullable', 'string', 'max:2000'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            // Step M-4.1: the agency's VAT/TRN number and the label to print it under.
            'tax_registration_number' => ['nullable', 'string', 'max:60'],
            'tax_registration_label' => ['nullable', 'string', 'max:30'],
            'invoice_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Step M-6.5 (Phase 9): hours a post may wait on review before it's flagged overdue.
            'approval_sla_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'restrict_staff_to_assigned_clients' => ['sometimes', 'boolean'],
            // Every member must hold a second factor. Guarded below: an owner cannot switch it on
            // without one themselves, because the middleware that enforces it would lock them out
            // of this very page.
            'require_two_factor' => ['sometimes', 'boolean'],
            // Minor units, so £50 is 5000. Without it the profitability page can report effort
            // but no margin.
            'blended_hourly_rate_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'upload_post_key' => ['nullable', 'string', 'max:2048'],
            'upload_post_webhook_secret' => ['nullable', 'string', 'max:2048'],
            // A queue worker posts to whatever is stored here, so it has to be an https URL and
            // nothing else — `http://` would put the credential on the wire in plaintext.
            'chat_webhook_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            // Step W-4.1: the organization's own Twilio sender. The token follows the same rule as
            // every other credential here — a blank submit keeps what is stored.
            'whatsapp_enabled' => ['nullable', 'boolean'],
            'whatsapp_account_sid' => ['nullable', 'string', 'max:64', 'starts_with:AC'],
            'whatsapp_auth_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_from' => ['nullable', 'string', 'max:32', new SendableWhatsAppNumber],
            'whatsapp_messaging_service_sid' => ['nullable', 'string', 'max:64', 'starts_with:MG'],
            'xai_key' => ['nullable', 'string', 'max:2048'],

            // Step GTM-3.1: white-label branding. Stored whatever the plan says (it is the org's
            // own data); `BrandingResolver` is what decides whether it reaches a client surface,
            // so a downgrade takes the branding off every surface without losing what was typed.
            'brand_accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_sender_name' => ['nullable', 'string', 'max:120'],
            'brand_reply_to' => ['nullable', 'email:filter', 'max:255'],
            'brand_footer' => ['nullable', 'string', 'max:300'],
        ];
    }
}
