<?php

namespace Tests\Unit\Services\Publishing;

use App\Contracts\PublishCredentials;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\PublishMode;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Services\Publishing\OrganizationOrPlatformCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Step B-5.1 — the credentials seam: byo orgs use their own key/secret and a per-account profile;
 * managed orgs use Alima's platform key/secret and their own isolated profile.
 */
class OrganizationOrPlatformCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.upload_post.platform_key', 'platform-key-xyz');
        Config::set('services.upload_post.platform_webhook_secret', 'platform-webhook-secret');
    }

    private function credentials(): PublishCredentials
    {
        return new OrganizationOrPlatformCredentials;
    }

    public function test_it_is_bound_as_the_default_publish_credentials(): void
    {
        $this->assertInstanceOf(OrganizationOrPlatformCredentials::class, app(PublishCredentials::class));
    }

    public function test_agency_org_is_byo_and_uses_its_own_key_secret_and_per_account_profile(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'upload_post_key' => 'agency-own-key',
            'upload_post_webhook_secret' => 'agency-own-secret',
        ]);
        $account = SocialAccount::factory()->for($org, 'organization')->create([
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-acct-1',
        ]);

        $credentials = $this->credentials();

        $this->assertSame(PublishMode::Byo, $credentials->mode($org));
        $this->assertSame('agency-own-key', $credentials->apiKeyForOrganization($org));
        $this->assertSame('agency-own-key', $credentials->apiKeyFor($account));
        $this->assertSame('agency-own-secret', $credentials->webhookSecretFor($org));
        $this->assertSame('ext-acct-1', $credentials->profileFor($account));
    }

    public function test_business_org_is_managed_and_uses_the_platform_key_secret(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business]);
        $account = SocialAccount::factory()->for($org, 'organization')->create([
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-acct-1',
        ]);

        $credentials = $this->credentials();

        $this->assertSame(PublishMode::Managed, $credentials->mode($org));
        $this->assertSame('platform-key-xyz', $credentials->apiKeyForOrganization($org));
        $this->assertSame('platform-key-xyz', $credentials->apiKeyFor($account));
        $this->assertSame('platform-webhook-secret', $credentials->webhookSecretFor($org));
        // The managed profile is the org's own, never the per-account identifier.
        $this->assertStringStartsWith("alima-org-{$org->id}-", $credentials->profileFor($account));
    }

    public function test_managed_profile_is_persisted_and_stable_across_calls(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business]);
        $account = SocialAccount::factory()->for($org, 'organization')->create(['platform' => Platform::Instagram]);

        $first = $this->credentials()->profileFor($account);
        $second = $this->credentials()->profileFor($account->fresh());

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $org->fresh()->upload_post_profile);
    }

    public function test_a_managed_org_can_never_see_another_orgs_profile(): void
    {
        $orgA = Organization::factory()->create(['type' => OrganizationType::Business]);
        $orgB = Organization::factory()->create(['type' => OrganizationType::Business]);
        $accountA = SocialAccount::factory()->for($orgA, 'organization')->create(['platform' => Platform::Instagram]);
        $accountB = SocialAccount::factory()->for($orgB, 'organization')->create(['platform' => Platform::Instagram]);

        $credentials = $this->credentials();

        $profileA = $credentials->profileFor($accountA);
        $profileB = $credentials->profileFor($accountB);

        $this->assertNotSame($profileA, $profileB);
        $this->assertStringContainsString("org-{$orgA->id}-", $profileA);
        $this->assertStringContainsString("org-{$orgB->id}-", $profileB);
    }

    public function test_explicit_publish_mode_override_flips_an_agency_to_managed(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'upload_post_key' => 'agency-own-key',
            'publish_mode' => PublishMode::Managed,
        ]);

        $credentials = $this->credentials();

        $this->assertSame(PublishMode::Managed, $credentials->mode($org));
        $this->assertSame('platform-key-xyz', $credentials->apiKeyForOrganization($org));
    }

    public function test_byo_org_with_no_key_resolves_to_an_empty_string(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'upload_post_key' => null,
            'upload_post_webhook_secret' => null,
        ]);

        $credentials = $this->credentials();

        $this->assertSame('', $credentials->apiKeyForOrganization($org));
        $this->assertSame('', $credentials->webhookSecretFor($org));
    }
}
