<?php

namespace Tests\Unit\Actions\SocialAccounts;

use App\Actions\SocialAccounts\CheckSocialAccountHealthAction;
use App\Enums\ConnectionStatus;
use App\Models\SocialAccount;
use App\Services\Publishing\NullPublishAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckSocialAccountHealthActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flips_a_connected_account_to_token_expired_when_the_adapter_reports_unhealthy(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::Connected]);

        $result = (new CheckSocialAccountHealthAction)(new NullPublishAdapter, $account);

        $this->assertFalse($result);
        $this->assertSame(ConnectionStatus::TokenExpired, $account->fresh()->connection_status);
    }

    public function test_it_leaves_a_healthy_connected_account_alone(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::Connected]);

        $adapter = new class extends NullPublishAdapter
        {
            public function health(SocialAccount $account): ?bool
            {
                return true;
            }
        };

        $result = (new CheckSocialAccountHealthAction)($adapter, $account);

        $this->assertTrue($result);
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
    }

    public function test_an_inconclusive_check_never_flips_a_connected_account(): void
    {
        // Regression: a vendor outage / rate limit used to mark working accounts token_expired.
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::Connected]);

        $result = (new CheckSocialAccountHealthAction)($this->adapterReporting(null), $account);

        $this->assertTrue($result);
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
        $this->assertNull($account->fresh()->last_health_error);
    }

    public function test_a_token_expired_account_the_vendor_reports_as_linked_is_restored(): void
    {
        $account = SocialAccount::factory()->create([
            'connection_status' => ConnectionStatus::TokenExpired,
            'last_health_error' => 'The publishing service no longer accepts this connection. Reconnect the account.',
        ]);

        $result = (new CheckSocialAccountHealthAction)($this->adapterReporting(true), $account);

        $this->assertTrue($result);
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
        $this->assertNull($account->fresh()->last_health_error);
    }

    private function adapterReporting(?bool $healthy): NullPublishAdapter
    {
        return new class($healthy) extends NullPublishAdapter
        {
            public function __construct(private ?bool $healthy) {}

            public function health(SocialAccount $account): ?bool
            {
                return $this->healthy;
            }
        };
    }

    public function test_it_never_checks_a_not_connected_account(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::NotConnected]);

        $result = (new CheckSocialAccountHealthAction)(new NullPublishAdapter, $account);

        $this->assertFalse($result);
        $this->assertSame(ConnectionStatus::NotConnected, $account->fresh()->connection_status);
    }
}
