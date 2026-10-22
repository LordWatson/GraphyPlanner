<?php

namespace App\Actions\Workspace;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Step B-3.3 — updates a business workspace's own settings (currently just the "require post
 * review" switch). Kept as an Action so the toggle's effect — and its log line — live in one place
 * as more workspace settings land in later phases.
 */
class UpdateWorkspaceSettingsAction
{
    public function __invoke(Organization $organization, bool $requirePostReview): Organization
    {
        $was = $organization->require_post_review;

        $organization->update(['require_post_review' => $requirePostReview]);

        Log::info('Workspace review setting updated', [
            'org_id' => $organization->id,
            'require_post_review' => $requirePostReview,
            'changed' => $was !== $requirePostReview,
        ]);

        return $organization;
    }
}
