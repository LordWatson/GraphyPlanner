<?php

namespace Tests\Unit\Services;

use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ClientHealthService
    {
        return new ClientHealthService;
    }

    private function healthyClientState(): array
    {
        return [
            'status' => ClientStatus::Active,
            'owner_user_id' => User::factory()->create()->id,
            'start_date' => now()->subMonth(),
            'retainer_amount' => 1000,
            'billing_cycle' => BillingCycle::Monthly,
        ];
    }

    public function test_a_fully_healthy_active_client_is_green(): void
    {
        $client = Client::factory()->create($this->healthyClientState());

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Green, $result['status']);
        $this->assertSame('Active client in good standing.', $result['reason']);
    }

    public function test_archived_client_is_green(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Archived]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Green, $result['status']);
        $this->assertSame('Client is archived; no active engagement risk.', $result['reason']);
    }

    public function test_offboarding_client_is_red(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Offboarding]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Client is offboarding.', $result['reason']);
    }

    public function test_active_client_without_owner_is_red(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'owner_user_id' => null]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Active client has no assigned owner.', $result['reason']);
    }

    public function test_active_client_without_any_billing_setup_is_red(): void
    {
        $client = Client::factory()->create([
            ...$this->healthyClientState(),
            'retainer_amount' => null,
            'billing_cycle' => null,
        ]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Red, $result['status']);
        $this->assertSame('Active client has no billing setup (missing retainer amount and billing cycle).', $result['reason']);
    }

    public function test_paused_client_is_amber(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'status' => ClientStatus::Paused]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Client is paused.', $result['reason']);
    }

    public function test_active_client_without_start_date_is_amber(): void
    {
        $client = Client::factory()->create([...$this->healthyClientState(), 'start_date' => null]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Active client has no start date recorded.', $result['reason']);
    }

    public function test_active_client_with_partial_billing_setup_is_amber(): void
    {
        $client = Client::factory()->create([
            ...$this->healthyClientState(),
            'retainer_amount' => 1000,
            'billing_cycle' => null,
        ]);

        $result = $this->service()->compute($client);

        $this->assertSame(ClientHealth::Amber, $result['status']);
        $this->assertSame('Client billing setup is incomplete (retainer amount or billing cycle missing).', $result['reason']);
    }
}
