<?php

namespace App\Actions\Workspace;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step B-5.1 — lazily assigns a managed organization its own stable Upload-Post profile username
 * (`organizations.upload_post_profile`), the key to isolating one managed org's publishing from
 * every other's under the shared platform key. The identifier is minted and persisted here; the
 * vendor-side profile itself is created idempotently by `UploadPostAdapter` on the first connect
 * (`ensureProfileExists`), so this Action stays a single, named DB write with no external call.
 *
 * Idempotent: an org that already has a profile keeps it, so the same org always publishes under
 * the same vendor profile across requests and reconnects.
 */
class EnsureManagedPublishProfileAction
{
    public function __invoke(Organization $organization): string
    {
        if (filled($organization->upload_post_profile)) {
            return (string) $organization->upload_post_profile;
        }

        // Deterministic prefix + random suffix: readable in the vendor dashboard and collision-free.
        $profile = 'alima-org-'.$organization->id.'-'.Str::lower(Str::random(8));

        $organization->forceFill(['upload_post_profile' => $profile])->save();

        Log::info('Assigned managed publish profile', [
            'org_id' => $organization->id,
            'upload_post_profile' => $profile,
        ]);

        return $profile;
    }
}
