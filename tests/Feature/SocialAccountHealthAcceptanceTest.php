<?php

namespace Tests\Feature;

use App\Contracts\PublishAdapter;
use App\Enums\ClientHealth;
use App\Enums\ConnectionStatus;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\NullPublishAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1.6 §12 acceptance test: running the token-health sweep against a disconnected vendor
 * token flips the account to `token_expired`, the owning client's health to Red, and produces a
 * Home "needs attention" row — all without touching anything else.
 */
class SocialAccountHealthAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_disconnected_token_flips_account_to_expired_client_to_red_and_surfaces_on_home(): void
    {
        $this->app->bind(PublishAdapter::class, NullPublishAdapter::class);

        $user = User::factory()->create();
        $client = Client::factory()->create([
            'org_id' => $user->org_id,
            'owner_user_id' => $user->id,
            'retainer_amount' => 1000,
            'billing_cycle' => \App\Enums\BillingCycle::Monthly,
            'start_date' => now()->subMonth(),
        ]);
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $user->org_id,
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $this->artisan('social-accounts:check-health')->assertSuccessful();

        $account->refresh();
        $this->assertSame(ConnectionStatus::TokenExpired, $account->connection_status);

        $health = $client->refresh()->health();
        $this->assertSame(ClientHealth::Red, $health['status']);

        $this->actingAs($user);
        $response = $this->get(route('needs-attention'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items.disconnectedAccounts', 1)
            ->where('items.disconnectedAccounts.0.account_id', $account->id)
        );
    }
}
