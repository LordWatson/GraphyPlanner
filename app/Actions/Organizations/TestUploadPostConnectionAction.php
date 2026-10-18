<?php

namespace App\Actions\Organizations;

use App\Contracts\PublishAdapter;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step I-1.6 — "Test connection" on the organization settings page: asks the publishing vendor
 * whether the organization's stored Upload-Post key is accepted, without publishing anything.
 */
class TestUploadPostConnectionAction
{
    public function __construct(private readonly PublishAdapter $adapter) {}

    public function __invoke(Organization $organization, User $actor): bool
    {
        $ok = filled($organization->upload_post_key) && $this->adapter->verifyCredentials($organization);

        Log::info('Upload-Post connection tested', [
            'org_id' => $organization->id,
            'user_id' => $actor->id,
            'ok' => $ok,
        ]);

        return $ok;
    }
}
