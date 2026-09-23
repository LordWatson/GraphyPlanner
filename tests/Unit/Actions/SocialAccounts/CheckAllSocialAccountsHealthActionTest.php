<?php

namespace Tests\Unit\Actions\SocialAccounts;

use App\Actions\SocialAccounts\CheckAllSocialAccountsHealthAction;
use App\Actions\SocialAccounts\CheckSocialAccountHealthAction;
use App\Enums\ConnectionStatus;
use App\Models\SocialAccount;
use App\Services\Publishing\NullPublishAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckAllSocialAccountsHealthActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_checks_connected_accounts_and_flags_unhealthy_ones(): void
    {
        $connected = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::Connected]);
        $notConnected = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::NotConnected]);
        $alreadyExpired = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::TokenExpired]);

        $flagged = (new CheckAllSocialAccountsHealthAction)(new NullPublishAdapter, new CheckSocialAccountHealthAction);

        $this->assertSame(1, $flagged);
        $this->assertSame(ConnectionStatus::TokenExpired, $connected->fresh()->connection_status);
        $this->assertSame(ConnectionStatus::NotConnected, $notConnected->fresh()->connection_status);
        $this->assertSame(ConnectionStatus::TokenExpired, $alreadyExpired->fresh()->connection_status);
    }
}
