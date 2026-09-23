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
            public function health(SocialAccount $account): bool
            {
                return true;
            }
        };

        $result = (new CheckSocialAccountHealthAction)($adapter, $account);

        $this->assertTrue($result);
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
    }

    public function test_it_never_checks_a_not_connected_account(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::NotConnected]);

        $result = (new CheckSocialAccountHealthAction)(new NullPublishAdapter, $account);

        $this->assertFalse($result);
        $this->assertSame(ConnectionStatus::NotConnected, $account->fresh()->connection_status);
    }
}
