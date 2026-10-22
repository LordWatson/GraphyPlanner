<?php

namespace Tests\Feature\Business;

use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ConnectStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step B-5.2 — one-tap "log in with…" connect for a business workspace: a platform card opens the
 * hosted connect flow for the org's managed Upload-Post profile, and the callback maps the result
 * back to the primary brand. No API key fields anywhere.
 */
class ManagedAccountConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.upload_post.platform_key', 'PLATFORM-KEY');
    }

    /**
     * @return array{0: Organization, 1: User, 2: Client}
     */
    private function businessWorkspace(): array
    {
        $org = Organization::factory()->business()->create();
        $brand = Client::factory()->for($org, 'organization')->primary()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $owner, $brand];
    }

    private function fakeVendorConnect(): void
    {
        Http::fake([
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://upload-post.test/connect/xyz'], 200),
            '*/uploadposts/users' => Http::response(['success' => true], 200),
        ]);
    }

    public function test_one_tap_connect_creates_the_brands_account_and_returns_the_hosted_url(): void
    {
        $this->fakeVendorConnect();
        [$org, $owner, $brand] = $this->businessWorkspace();

        $response = $this->actingAs($owner)
            ->postJson(route('business.accounts.connect-platform', ['platform' => Platform::Instagram->value]));

        $response->assertOk();
        $response->assertJson(['url' => 'https://upload-post.test/connect/xyz']);

        // The account is created under the primary brand, in managed mode (no key typed).
        $this->assertDatabaseHas('social_accounts', [
            'client_id' => $brand->id,
            'org_id' => $org->id,
            'platform' => Platform::Instagram->value,
            'connection_status' => ConnectionStatus::NotConnected->value,
        ]);

        // The vendor was authenticated with the platform key, never a per-org one.
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Apikey PLATFORM-KEY'));
    }

    public function test_the_callback_connects_the_account_under_the_primary_brand(): void
    {
        $this->fakeVendorConnect();
        [$org, $owner, $brand] = $this->businessWorkspace();

        $this->actingAs($owner)
            ->postJson(route('business.accounts.connect-platform', ['platform' => Platform::Instagram->value]))
            ->assertOk();

        $account = SocialAccount::query()->where('client_id', $brand->id)->firstOrFail();
        $state = app(ConnectStateStore::class)->issue($account, $owner, ConnectSocialAccountAction::STATE_FLOW);

        $response = $this->get(route('social-accounts.callback', [
            'social_account_id' => $account->id,
            'profile' => 'alima-org-'.$org->id,
            'state' => $state,
        ]));

        $response->assertOk();
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
        $this->assertNotNull($account->fresh()->connected_at);
        $this->assertSame($brand->id, $account->fresh()->client_id);
    }

    public function test_a_tampered_callback_is_rejected(): void
    {
        [, , $brand] = $this->businessWorkspace();
        $account = SocialAccount::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'platform' => Platform::Instagram,
            'connection_status' => ConnectionStatus::NotConnected,
        ]);

        // No state, and a forged state, are both refused; the account stays unconnected.
        $this->get(route('social-accounts.callback', ['social_account_id' => $account->id, 'profile' => 'evil']))
            ->assertForbidden();
        $this->get(route('social-accounts.callback', ['social_account_id' => $account->id, 'profile' => 'evil', 'state' => 'forged']))
            ->assertForbidden();

        $this->assertSame(ConnectionStatus::NotConnected, $account->fresh()->connection_status);
    }

    public function test_reconnecting_an_expired_account_reuses_the_same_row(): void
    {
        $this->fakeVendorConnect();
        [$org, $owner, $brand] = $this->businessWorkspace();

        $expired = SocialAccount::factory()->for($brand)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'connection_status' => ConnectionStatus::TokenExpired,
            'last_health_error' => 'Instagram needs you to reconnect.',
        ]);

        $this->actingAs($owner)
            ->postJson(route('business.accounts.connect-platform', ['platform' => Platform::Instagram->value]))
            ->assertOk();

        // No second row — the one-tap connect found and reused the expired account.
        $this->assertSame(1, SocialAccount::query()->where('client_id', $brand->id)->where('platform', Platform::Instagram)->count());

        $state = app(ConnectStateStore::class)->issue($expired, $owner, ConnectSocialAccountAction::STATE_FLOW);
        $this->get(route('social-accounts.callback', [
            'social_account_id' => $expired->id,
            'profile' => 'alima-org-'.$org->id,
            'state' => $state,
        ]))->assertOk();

        $fresh = $expired->fresh();
        $this->assertSame(ConnectionStatus::Connected, $fresh->connection_status);
        $this->assertNull($fresh->last_health_error);
    }

    public function test_an_unknown_platform_is_not_found(): void
    {
        [, $owner] = $this->businessWorkspace();

        $this->actingAs($owner)
            ->postJson(route('business.accounts.connect-platform', ['platform' => 'myspace']))
            ->assertNotFound();
    }

    public function test_an_agency_cannot_reach_the_business_connect_route(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->postJson(route('business.accounts.connect-platform', ['platform' => Platform::Instagram->value]))
            ->assertNotFound();
    }
}
