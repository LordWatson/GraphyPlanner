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
     * @param  array{default_timezone: string, default_currency: string, billing_details?: string|null, payment_instructions?: string|null, tax_registration_number?: string|null, tax_registration_label?: string|null, invoice_payment_terms_days?: int|string|null, upload_post_key?: string|null, upload_post_webhook_secret?: string|null, xai_key?: string|null}  $data
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

            if (! empty($data['upload_post_key'])) {
                $organization->upload_post_key = $data['upload_post_key'];
            }

            if (! empty($data['upload_post_webhook_secret'])) {
                $organization->upload_post_webhook_secret = $data['upload_post_webhook_secret'];
            }

            if (! empty($data['xai_key'])) {
                $organization->xai_key = $data['xai_key'];
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
            ]);

            return $organization;
        });
    }
}
