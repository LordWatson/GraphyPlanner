<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateOrganizationSettingsAction
{
    /**
     * Update the organization's settings (default timezone, default currency, invoicing profile,
     * Upload-Post key, xAI key).
     *
     * Keys are only ever written when present in $data (an empty/omitted value leaves
     * the currently stored key untouched, so the form never needs to redisplay a secret
     * to "keep" it). Saving a new Upload-Post key also registers the publish webhook with the
     * vendor and stores its secret (Step I-3.6 / M-2.5).
     *
     * @param  array{default_timezone: string, default_currency: string, approval_sla_hours?: int|string|null, billing_details?: string|null, payment_instructions?: string|null, tax_registration_number?: string|null, tax_registration_label?: string|null, invoice_payment_terms_days?: int|string|null, upload_post_key?: string|null, upload_post_webhook_secret?: string|null, chat_webhook_url?: string|null, restrict_staff_to_assigned_clients?: bool|int|string|null, require_two_factor?: bool|int|string|null, blended_hourly_rate_minor?: int|string|null, xai_key?: string|null, whatsapp_enabled?: bool|int|string|null, whatsapp_account_sid?: string|null, whatsapp_auth_token?: string|null, whatsapp_from?: string|null, whatsapp_messaging_service_sid?: string|null}  $data
     */
    public function __invoke(Organization $organization, array $data): Organization
    {
        return DB::transaction(function () use ($organization, $data) {
            $organization->default_timezone = $data['default_timezone'];
            $organization->default_currency = $data['default_currency'];

            // The invoicing profile is optional on the form: only touched when submitted, so
            // older clients of this endpoint (and its tests) keep working unchanged.
            if (array_key_exists('billing_details', $data)) {
                $organization->billing_details = $data['billing_details'];
            }

            if (array_key_exists('payment_instructions', $data)) {
                $organization->payment_instructions = $data['payment_instructions'];
            }

            // Step M-4.1: the agency's tax registration (drives the "Tax invoice" heading).
            if (array_key_exists('tax_registration_number', $data)) {
                $organization->tax_registration_number = $data['tax_registration_number'];
            }

            if (array_key_exists('tax_registration_label', $data)) {
                $organization->tax_registration_label = $data['tax_registration_label'];
            }

            if (isset($data['invoice_payment_terms_days'])) {
                $organization->invoice_payment_terms_days = (int) $data['invoice_payment_terms_days'];
            }

            // Step M-6.5: the approval SLA can be set or cleared (null = no SLA, never overdue).
            if (array_key_exists('approval_sla_hours', $data)) {
                $organization->approval_sla_hours = $data['approval_sla_hours'] !== null ? (int) $data['approval_sla_hours'] : null;
            }

            if (! empty($data['upload_post_key'])) {
                $organization->upload_post_key = $data['upload_post_key'];
            }

            if (! empty($data['upload_post_webhook_secret'])) {
                $organization->upload_post_webhook_secret = $data['upload_post_webhook_secret'];
            }

            // Per-client staff access. Only touched when submitted, so other callers of this
            // action are unaffected. Turning it on restricts every non-Owner immediately — a
            // member with no assignments sees nothing until somebody puts them on a client, which
            // is the safe direction for a confidentiality setting.
            // What an hour of the agency's time costs, for client profitability. Nullable: an
            // unset rate reports effort without inventing a margin.
            if (array_key_exists('blended_hourly_rate_minor', $data)) {
                $organization->blended_hourly_rate_minor = $data['blended_hourly_rate_minor'] !== null && $data['blended_hourly_rate_minor'] !== ''
                    // Floored at zero because the column is unsigned — a negative would throw at
                    // the database rather than being refused here.
                    ? max(0, (int) $data['blended_hourly_rate_minor'])
                    : null;
            }

            if (array_key_exists('restrict_staff_to_assigned_clients', $data)) {
                $organization->restrict_staff_to_assigned_clients = (bool) $data['restrict_staff_to_assigned_clients'];
            }

            // Org-wide two-factor. The form refuses to turn this on for an owner who has no second
            // factor themselves (see `OrganizationSettingsUpdateRequest::after`), so by the time it
            // reaches here somebody can still get back in.
            if (array_key_exists('require_two_factor', $data)) {
                $organization->require_two_factor = (bool) $data['require_two_factor'];
            }

            if (! empty($data['xai_key'])) {
                $organization->xai_key = $data['xai_key'];
            }

            // The team-chat webhook is a credential, so it follows the same rule as the keys above:
            // a blank submit keeps what is stored, and the form never redisplays it. Turning the
            // integration off is a deliberate act with its own button
            // (`OrganizationController::removeChatWebhook`), not something a blank field does by
            // accident while somebody was editing their timezone.
            if (! empty($data['chat_webhook_url'])) {
                $organization->chat_webhook_url = $data['chat_webhook_url'];
            }

            // Step W-4.1: the Twilio auth token is a credential and follows the rule above — a
            // blank submit keeps it. The identifiers beside it are not secrets, so they clear
            // normally, and `whatsapp_enabled` is the off switch that leaves the credentials in
            // place (`WhatsAppCredentials::isConfiguredFor()` reads it first).
            if (! empty($data['whatsapp_auth_token'])) {
                $organization->whatsapp_auth_token = $data['whatsapp_auth_token'];
            }

            foreach (['whatsapp_account_sid', 'whatsapp_from', 'whatsapp_messaging_service_sid'] as $field) {
                if (array_key_exists($field, $data)) {
                    $organization->{$field} = $data[$field] ?: null;
                }
            }

            if (array_key_exists('whatsapp_enabled', $data)) {
                $organization->whatsapp_enabled = (bool) $data['whatsapp_enabled'];
            }

            // Step GTM-3.1: white-label branding. Saved whether or not the plan includes it —
            // `BrandingResolver` decides whether it reaches a client, so a downgrade hides the
            // branding without throwing away what the agency typed.
            foreach (['brand_accent', 'brand_sender_name', 'brand_reply_to', 'brand_footer'] as $field) {
                if (array_key_exists($field, $data)) {
                    $organization->{$field} = $data[$field] ?: null;
                }
            }

            $organization->save();

            // Step I-3.6 / M-2.5: a new Upload-Post key registers the publish webhook and stores
            // its secret automatically (unless a secret was typed in by hand with it).
            if (! empty($data['upload_post_key']) && empty($data['upload_post_webhook_secret'])) {
                DB::afterCommit(fn () => app(RegisterUploadPostWebhookAction::class)($organization));
            }

            Log::info('Organization settings updated', [
                'org_id' => $organization->id,
                'upload_post_key_updated' => ! empty($data['upload_post_key']),
                'upload_post_webhook_secret_updated' => ! empty($data['upload_post_webhook_secret']),
                'xai_key_updated' => ! empty($data['xai_key']),
                'whatsapp_token_updated' => ! empty($data['whatsapp_auth_token']),
            ]);

            return $organization;
        });
    }
}
