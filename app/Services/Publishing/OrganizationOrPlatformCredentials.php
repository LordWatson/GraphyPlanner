<?php

namespace App\Services\Publishing;

use App\Actions\SocialAccounts\EnsurePublishProfileAction;
use App\Contracts\PublishCredentials;
use App\Enums\PublishMode;
use App\Models\Organization;
use App\Models\SocialAccount;

/**
 * Step B-5.1 — the default `PublishCredentials`: byo orgs use their own stored Upload-Post key and
 * webhook secret (unchanged agency behaviour); managed orgs (every business workspace, plus any
 * agency a super admin flips via `organizations.publish_mode`) use Alima's single platform key.
 * The publish profile is per client in both modes (see `profileFor()`).
 *
 * No key or secret is ever logged here — this class only resolves and returns them.
 */
class OrganizationOrPlatformCredentials implements PublishCredentials
{
    public function __construct(
        private readonly EnsurePublishProfileAction $ensureProfile = new EnsurePublishProfileAction,
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

    /**
     * One profile per *client*, in both modes: Upload-Post charges per profile and a profile holds
     * one account per platform, so neither a per-account profile (five networks, five profiles)
     * nor a per-org one (two brands, one Instagram slot) is right. Client ids are global, so this
     * is still a profile no other organization can name.
     */
    public function profileFor(SocialAccount $account): string
    {
        return ($this->ensureProfile)($account);
    }

    public function profileForConnect(SocialAccount $account): string
    {
        return ($this->ensureProfile)($account, relinking: true);
    }
}
