<?php

namespace App\Actions\Workspace;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Step B-3.3 / B-8.2 — updates a business workspace's own settings (the "require post review" switch
 * and the weekly Insights digest opt-out). Kept as an Action so each toggle's effect — and its log
 * line — live in one place as more workspace settings land in later phases.
 */
class UpdateWorkspaceSettingsAction
{
    public function __invoke(Organization $organization, bool $requirePostReview, bool $insightsDigestOptOut): Organization
    {
        $before = [
            'require_post_review' => $organization->require_post_review,
            'insights_digest_opt_out' => $organization->insights_digest_opt_out,
        ];

        $organization->update([
            'require_post_review' => $requirePostReview,
            'insights_digest_opt_out' => $insightsDigestOptOut,
        ]);

        Log::info('Workspace settings updated', [
            'org_id' => $organization->id,
            'require_post_review' => $requirePostReview,
            'insights_digest_opt_out' => $insightsDigestOptOut,
            'changed' => $before !== [
                'require_post_review' => $requirePostReview,
                'insights_digest_opt_out' => $insightsDigestOptOut,
            ],
        ]);

        return $organization;
    }
}
