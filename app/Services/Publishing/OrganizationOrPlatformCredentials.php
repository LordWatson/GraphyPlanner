<?php

namespace App\Services\Publishing;

use App\Actions\Workspace\EnsureManagedPublishProfileAction;
use App\Contracts\PublishCredentials;
use App\Enums\PublishMode;
use App\Models\Organization;
use App\Models\SocialAccount;

/**
 * Step B-5.1 — the default `PublishCredentials`: byo orgs use their own stored Upload-Post key and
 * webhook secret (unchanged agency behaviour); managed orgs (every business workspace, plus any
 * agency a super admin flips via `organizations.publish_mode`) use Alima's single platform key and
 * their own isolated Upload-Post profile.
 *
 * No key or secret is ever logged here — this class only resolves and returns them.
 */
class OrganizationOrPlatformCredentials implements PublishCredentials
{
    public function __construct(
        private readonly EnsureManagedPublishProfileAction $ensureProfile = new EnsureManagedPublishProfileAction,
    ) {}

    public function mode(Organization $organization): PublishMode
    {
        // An explicit override wins (super-admin "flip"); otherwise business = managed, agency = byo.
        return $organization->publish_mode
            ?? ($organization->isBusiness() ? PublishMode::Managed : PublishMode::Byo);
    }

    public function apiKeyFor(SocialAccount $account): string
    {
        $organization = $account->loadMissing('organization')->organization;

        return $organization !== null ? $this->apiKeyForOrganization($organization) : '';
    }

    public function apiKeyForOrganization(Organization $organization): string
    {
        if ($this->mode($organization) === PublishMode::Managed) {
            return (string) config('services.upload_post.platform_key', '');
        }

        return (string) ($organization->upload_post_key ?? '');
    }

    public function webhookSecretFor(Organization $organization): string
    {
        if ($this->mode($organization) === PublishMode::Managed) {
            return (string) config('services.upload_post.platform_webhook_secret', '');
        }

        return (string) ($organization->upload_post_webhook_secret ?? '');
    }

    public function profileFor(SocialAccount $account): string
    {
        $organization = $account->loadMissing('organization')->organization;

        if ($organization !== null && $this->mode($organization) === PublishMode::Managed) {
            // One isolated profile per managed org — never another org's, never a per-account one.
            return ($this->ensureProfile)($organization);
        }

        // byo: the account's own vendor identifier, preserving the Step 1.2/1.3 behaviour.
        return $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id;
    }
}
