<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Step 2.5 — DailyBriefController lists the client-scoped DailyBrief rows behind the Clients
 * module's "Daily Briefs" tab. Mirrors AiControllerTest's authz matrix (feature flag, org
 * scoping, Owner/Strategist only).
 */
class DailyBriefControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
    }

    public function test_it_returns_only_the_requested_clients_briefs_for_an_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();

        DailyBrief::factory()->create(['org_id' => $org->id, 'client_id' => $client->id, 'brief_date' => '2026-10-14']);
        DailyBrief::factory()->create(['org_id' => $org->id, 'client_id' => $otherClient->id, 'brief_date' => '2026-10-14']);

        $response = $this->actingAs($owner)->getJson(route('clients.daily-briefs.index', $client));

        $response->assertOk();
        $response->assertJsonCount(1, 'briefs');
    }

    public function test_designer_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->getJson(route('clients.daily-briefs.index', $client));

        $response->assertForbidden();
    }

    public function test_user_from_another_org_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $otherOrg = Organization::factory()->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->getJson(route('clients.daily-briefs.index', $client));

        $response->assertForbidden();
    }

    public function test_it_is_not_found_when_the_feature_is_disabled(): void
    {
        Config::set('ai.enabled', false);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->getJson(route('clients.daily-briefs.index', $client));

        $response->assertNotFound();
    }
}
