<?php

namespace Tests\Feature\Clients;

use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientHealthHistory;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step I-2.5 — the roster is paginated, searched, filtered and sorted in SQL, and health is read
 * from the materialized column that the trigger events keep current.
 */
class ClientRosterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    public function test_the_roster_is_paginated(): void
    {
        Client::factory()->for($this->org, 'organization')->count(30)->create();

        $this->actingAs($this->owner)->get(route('clients.index'))
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 25)
                ->where('clients.total', 30)
                ->where('clients.last_page', 2));

        $this->actingAs($this->owner)->get(route('clients.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page->has('clients.data', 5));

        $this->actingAs($this->owner)->get(route('clients.index', ['per_page' => 50]))
            ->assertInertia(fn ($page) => $page->has('clients.data', 30));
    }

    public function test_search_matches_name_legal_name_and_tags(): void
    {
        $byName = $this->client(['name' => 'Northwind Coffee']);
        $byLegal = $this->client(['name' => 'Acme', 'legal_name' => 'Northwind Holdings Ltd']);
        $byTag = $this->client(['name' => 'Bravo', 'tags' => ['northwind-partner']]);
        $this->client(['name' => 'Unrelated']);

        $this->actingAs($this->owner)->get(route('clients.index', ['search' => 'northwind']))
            ->assertInertia(fn ($page) => $page
                ->where('clients.total', 3)
                ->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$byName->id, $byLegal->id, $byTag->id])->sort()->values()->all()));
    }

    public function test_filters_by_status_health_owner_and_tag(): void
    {
        $strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $paused = $this->client(['status' => ClientStatus::Paused, 'tags' => ['retail']]);
        $owned = $this->client(['owner_user_id' => $strategist->id]);
        $red = $this->client(['status' => ClientStatus::Offboarding]);

        $ids = fn (array $query) => fn ($page) => $page->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->all() === $query);

        $this->actingAs($this->owner)->get(route('clients.index', ['status' => 'paused']))->assertInertia($ids([$paused->id]));
        $this->actingAs($this->owner)->get(route('clients.index', ['health' => 'red']))->assertInertia($ids([$red->id]));
        $this->actingAs($this->owner)->get(route('clients.index', ['owner' => $strategist->id]))->assertInertia($ids([$owned->id]));
        $this->actingAs($this->owner)->get(route('clients.index', ['tag' => 'retail']))->assertInertia($ids([$paused->id]));
    }

    public function test_sorts_by_health_and_hides_retainer_order_from_non_billing_roles(): void
    {
        $green = $this->client(['name' => 'A green']);
        $red = $this->client(['name' => 'B red', 'status' => ClientStatus::Offboarding]);
        $amber = $this->client(['name' => 'C amber', 'status' => ClientStatus::Paused]);

        $this->actingAs($this->owner)->get(route('clients.index', ['sort' => 'health']))
            ->assertInertia(fn ($page) => $page->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$red->id, $amber->id, $green->id]));

        $green->update(['retainer_amount' => 9000]);
        $red->update(['retainer_amount' => 100]);
        $amber->update(['retainer_amount' => 5000]);

        $this->actingAs($this->owner)->get(route('clients.index', ['sort' => 'retainer', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$green->id, $amber->id, $red->id]));

        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->get(route('clients.index', ['sort' => 'retainer', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page
                ->where('clients.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$amber->id, $red->id, $green->id])
                ->where('clients.data.0.retainer_amount', null));
    }

    public function test_health_is_read_from_the_stored_column(): void
    {
        $client = $this->client();
        DB::table('clients')->where('id', $client->id)->update(['health_status' => 'amber', 'health_reason' => 'Stored reason.']);

        $this->actingAs($this->owner)->get(route('clients.index'))
            ->assertInertia(fn ($page) => $page
                ->where('clients.data.0.health', 'amber')
                ->where('clients.data.0.health_reason', 'Stored reason.'));
    }

    public function test_health_recomputes_when_the_client_changes(): void
    {
        $client = $this->client();
        $this->assertSame(ClientHealth::Green, $client->fresh()->health_status);

        $client->update(['status' => ClientStatus::Offboarding]);

        $this->assertSame(ClientHealth::Red, $client->fresh()->health_status);
        $this->assertSame(['green', 'red'], ClientHealthHistory::query()->where('client_id', $client->id)->orderBy('id')->pluck('health_status')->map->value->all());
    }

    public function test_health_recomputes_when_an_account_token_expires_and_recovers(): void
    {
        $client = $this->client();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $this->org->id, 'connection_status' => ConnectionStatus::Connected]);

        $account->update(['connection_status' => ConnectionStatus::TokenExpired]);
        $this->assertSame(ClientHealth::Red, $client->fresh()->health_status);

        $account->delete();
        $this->assertSame(ClientHealth::Green, $client->fresh()->health_status);
    }

    public function test_the_nightly_sweep_repairs_drift(): void
    {
        $client = $this->client();
        DB::table('clients')->where('id', $client->id)->update(['health_status' => 'red', 'health_reason' => 'Stale.']);

        $this->artisan('clients:recompute-health')->assertSuccessful();

        $this->assertSame(ClientHealth::Green, $client->fresh()->health_status);
    }

    /**
     * A green-by-default active client (owner, start date, billing all set).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): Client
    {
        return Client::factory()->for($this->org, 'organization')->create([
            'status' => ClientStatus::Active,
            'owner_user_id' => $this->owner->id,
            'start_date' => '2026-01-01',
            'retainer_amount' => 1000,
            'billing_cycle' => BillingCycle::Monthly,
            ...$attributes,
        ]);
    }
}
