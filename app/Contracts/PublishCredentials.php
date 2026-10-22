<?php

namespace App\Contracts;

use App\Enums\PublishMode;
use App\Models\Organization;
use App\Models\SocialAccount;

/**
 * Step B-5.1 — the single seam for resolving an organization's publishing-vendor credentials.
 *
 * Nothing in the app reads `organizations.upload_post_key` / `upload_post_webhook_secret` directly
 * any more: the adapter, the webhook verifier, the music/engagement readers and the connect flow
 * all ask this contract. That lets a business workspace publish in "managed" mode (one platform
 * key, one profile per org) with no per-org key, while agencies keep their "byo" key — without a
 * single `if ($org->type === ...)` branch leaking into the vendor code.
 *
 * Implementations must never log a key or secret.
 */
interface PublishCredentials
{
    /**
     * Whether this org brings its own vendor key (`byo`) or uses Alima's managed platform key
     * (`managed`). Business orgs are always managed; agencies are byo unless overridden.
     */
    public function mode(Organization $organization): PublishMode;

    /**
     * The Upload-Post API key to authenticate a request for this account: the account's own
     * organization key in byo mode, the shared platform key in managed mode. Never empty on a
     * correctly-configured org; an empty string means "not configured" (the vendor will reject it).
     */
    public function apiKeyFor(SocialAccount $account): string;

    /**
     * The Upload-Post API key for an org-level call (credential check, webhook registration) that
     * has no single account in hand.
     */
    public function apiKeyForOrganization(Organization $organization): string;

    /**
     * The webhook signing secret to verify a delivery for this org: the org's own secret in byo
     * mode, the platform-wide secret in managed mode. Empty string when none is configured yet.
     */
    public function webhookSecretFor(Organization $organization): string;

    /**
     * The Upload-Post profile ("user") this account publishes under. In managed mode this is the
     * org's single isolated profile (so no managed org can ever touch another's profile); in byo
     * mode it is the account's own vendor identifier, preserving today's per-account behaviour.
     */
    public function profileFor(SocialAccount $account): string;
}
