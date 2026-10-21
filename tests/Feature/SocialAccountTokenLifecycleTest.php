<?php

namespace Tests\Feature;

use App\Actions\SocialAccounts\CheckAllSocialAccountsHealthAction;
use App\Actions\SocialAccounts\CheckSocialAccountHealthAction;
use App\Actions\SocialAccounts\CheckTokenExpiryAction;
use App\Actions\SocialAccounts\ConnectMetaAudioAccessAction;
use App\Contracts\PublishAdapter;
use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountTokenExpiring;
use App\Services\ClientHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Step I-3.9 / M-2.7 — tokens are refreshed before they lapse, warned about at 7 days and 1 day,
 * and the hourly check records its last result on the account.
 */
class SocialAccountTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-19 12:00:00');
        Notification::fake();
        config(['services.facebook.client_id' => 'app-id', 'services.facebook.client_secret' => 'app-secret']);

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create([
            'status' => ClientStatus::Active,
            'start_date' => '2026-01-01',
            'retainer_amount' => 1000,
            'billing_cycle' => BillingCycle::Monthly,
        ]);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client->update(['owner_user_id' => $this->owner->id]);
    }

    private function account(string $expiresAt): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'handle' => '@alima',
            'connection_status' => ConnectionStatus::Connected,
            'meta_access_token' => 'old-token',
            'meta_access_token_expires_at' => $expiresAt,
        ]);
    }

    public function test_an_account_expiring_in_5_days_produces_a_home_row_and_an_amber_health_reason(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session expired']], 400)]);
        $account = $this->account('2026-10-24 12:00:00');

        app(CheckTokenExpiryAction::class)($account);

        $this->assertSame(SocialAccount::TOKEN_EXPIRES_THIS_WEEK, $account->refresh()->token_expiry_warning);

        $health = app(ClientHealthService::class)->compute($this->client->refresh());
        $this->assertSame(ClientHealth::Amber, $health['status']);
        $this->assertSame('The Instagram connection for @alima expires in 5 days — reconnect it.', $health['reason']);
        $this->assertSame(ClientHealth::Amber, $this->client->refresh()->health_status, 'The stored health follows.');

        $this->actingAs($this->owner)->get(route('needs-attention'))
            ->assertInertia(fn ($page) => $page
                ->where('items.disconnectedAccounts.total', 1)
                ->where('items.disconnectedAccounts.items.0.account_id', $account->id)
                ->where('items.disconnectedAccounts.items.0.reason', 'Connection expires in 5 days'));

        Notification::assertSentTo($this->owner, SocialAccountTokenExpiring::class);
    }

    public function test_each_warning_goes_out_once_per_stage(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 400)]);
        $account = $this->account('2026-10-24 12:00:00');
        $check = app(CheckTokenExpiryAction::class);

        $check($account);
        $check($account->refresh());
        Notification::assertSentToTimes($this->owner, SocialAccountTokenExpiring::class, 1);

        $this->travelTo('2026-10-24 01:00:00');
        $check($account->refresh());
        $this->assertSame(SocialAccount::TOKEN_EXPIRES_TOMORROW, $account->refresh()->token_expiry_warning);
        Notification::assertSentToTimes($this->owner, SocialAccountTokenExpiring::class, 2);
    }

    public function test_a_token_meta_will_refresh_is_refreshed_silently(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'new-token', 'expires_in' => 60 * 24 * 3600])]);
        $account = $this->account('2026-10-24 12:00:00');

        $stage = app(CheckTokenExpiryAction::class)($account);

        $this->assertNull($stage);
        $account->refresh();
        $this->assertSame('new-token', $account->meta_access_token);
        $this->assertTrue($account->meta_access_token_expires_at->isAfter(now()->addDays(50)));
        $this->assertNull($account->token_expiry_warning);
        Http::assertSent(fn ($request) => $request['grant_type'] === 'fb_exchange_token' && $request['fb_exchange_token'] === 'old-token');
        Notification::assertNothingSent();
    }

    public function test_the_hourly_check_records_its_last_result_on_the_account(): void
    {
        Http::fake();
        $healthy = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'connection_status' => ConnectionStatus::Connected]);
        $broken = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'connection_status' => ConnectionStatus::Connected]);

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldReceive('health')->andReturnUsing(fn (SocialAccount $account) => $account->is($healthy));

        app(CheckAllSocialAccountsHealthAction::class)($adapter, app(CheckSocialAccountHealthAction::class));

        $healthy->refresh();
        $broken->refresh();
        $this->assertNotNull($healthy->last_health_check_at);
        $this->assertNull($healthy->last_health_error);
        $this->assertSame(ConnectionStatus::TokenExpired, $broken->connection_status);
        $this->assertStringContainsString('no longer accepts this connection', (string) $broken->last_health_error);
    }

    public function test_reconnecting_clears_the_warning(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 400)]);
        $account = $this->account('2026-10-24 12:00:00');
        app(CheckTokenExpiryAction::class)($account);

        app(ConnectMetaAudioAccessAction::class)($account->refresh(), 'fresh-token', now()->addDays(60), 'ig-1');

        $this->assertNull($account->refresh()->token_expiry_warning);
        // Step M-3.4: the token reason is gone (other v2 signals, e.g. an empty calendar, may remain).
        $keys = array_column($this->client->refresh()->health_reasons ?? [], 'key');
        $this->assertNotContains('token_expiring', $keys);
        $this->assertNotContains('token_expired', $keys);
        $this->assertNotSame(ClientHealth::Red, $this->client->health_status);
    }
}
