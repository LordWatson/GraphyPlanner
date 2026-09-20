<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateOrganizationSettingsAction
{
    /**
     * Update the organization's settings (default timezone, default currency, Upload-Post key, xAI key).
     *
     * Keys are only ever written when present in $data (an empty/omitted value leaves
     * the currently stored key untouched, so the form never needs to redisplay a secret
     * to "keep" it).
     *
     * @param  array{default_timezone: string, default_currency: string, upload_post_key?: string|null, upload_post_webhook_secret?: string|null, xai_key?: string|null}  $data
     */
    public function __invoke(Organization $organization, array $data): Organization
    {
        return DB::transaction(function () use ($organization, $data) {
            $organization->default_timezone = $data['default_timezone'];
            $organization->default_currency = $data['default_currency'];

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
