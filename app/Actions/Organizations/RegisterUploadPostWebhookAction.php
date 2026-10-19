<?php

namespace App\Actions\Organizations;

use App\Contracts\PublishAdapter;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Step I-3.6 / M-2.5 — registers the organization's publish-outcome webhook with Upload-Post and
 * stores the signing secret it issues, so nobody has to copy it across from the vendor dashboard.
 * Runs whenever a new Upload-Post key is saved. The secret is stored encrypted and never logged.
 */
class RegisterUploadPostWebhookAction
{
    public function __construct(private readonly PublishAdapter $adapter) {}

    public function __invoke(Organization $organization): bool
    {
        if (blank($organization->upload_post_key)) {
            return false;
        }

        $secret = $this->adapter->registerWebhook($organization, route('webhooks.upload-post'));

        if ($secret === null) {
            Log::warning('Upload-Post webhook not registered; the poll fallback still confirms deliveries', ['org_id' => $organization->id]);

            return false;
        }

        $organization->forceFill(['upload_post_webhook_secret' => $secret])->save();

        Log::info('Upload-Post webhook secret stored', ['org_id' => $organization->id]);

        return true;
    }
}
