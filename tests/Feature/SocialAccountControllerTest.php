<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_social_account_with_a_valid_timezone(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.social-accounts.store', $client), [
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('social_accounts', [
            'client_id' => $client->id,
            'org_id' => $org->id,
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
            'timezone' => 'Europe/Amsterdam',
            'connection_status' => ConnectionStatus::NotConnected->value,
        ]);
    }

    public function test_social_account_requires_a_valid_iana_timezone(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.social-accounts.store', $client), [
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
            'timezone' => 'Not/ARealTimezone',
        ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_social_account_requires_a_timezone(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.social-accounts.store', $client), [
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
        ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_designer_cannot_create_a_social_account(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.social-accounts.store', $client), [
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_user_from_another_org_cannot_create_a_social_account_for_a_client(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->post(route('clients.social-accounts.store', $client), [
            'platform' => Platform::Instagram->value,
            'handle' => '@acme',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_owner_can_update_a_social_account(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->put(route('social-accounts.update', $account), [
            'platform' => $account->platform->value,
            'handle' => '@updated-handle',
            'timezone' => 'America/New_York',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertSame('@updated-handle', $account->fresh()->handle);
    }

    public function test_owner_can_delete_a_social_account(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('social-accounts.destroy', $account));

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseMissing('social_accounts', ['id' => $account->id]);
    }

    public function test_owner_can_start_the_connect_flow_and_receives_the_vendor_url_as_json(): void
    {
        Http::fake([
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://upload-post.test/connect/abc'], 200),
            '*/uploadposts/users' => Http::response(['success' => true], 200),
        ]);

        $org = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('social-accounts.connect', $account));

        $response->assertOk();
        $response->assertJson(['url' => 'https://upload-post.test/connect/abc']);
    }

    public function test_connect_flow_returns_a_json_error_when_the_vendor_rejects_the_request(): void
    {
        Http::fake([
            '*/uploadposts/users' => Http::response(['success' => false, 'error' => 'Profile limit reached'], 422),
        ]);

        $org = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('social-accounts.connect', $account));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_designer_cannot_start_the_connect_flow(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->post(route('social-accounts.connect', $account));

        $response->assertForbidden();
    }

    public function test_the_vendor_callback_persists_the_external_profile_id_and_connects_the_account(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'connection_status' => ConnectionStatus::NotConnected,
        ]);

        $response = $this->get(route('social-accounts.callback', [
            'social_account_id' => $account->id,
            'profile' => 'vendor-profile-123',
        ]));

        $response->assertOk();
        $response->assertViewIs('social-accounts.connected');
        $response->assertViewHas('connected', true);
        $this->assertSame('vendor-profile-123', $account->fresh()->external_profile_id);
        $this->assertSame(ConnectionStatus::Connected, $account->fresh()->connection_status);
        $this->assertNotNull($account->fresh()->connected_at);
    }

    public function test_client_reviewer_sees_social_accounts_scoped_to_their_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        SocialAccount::factory()->for($client)->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('socialAccounts', 1));
    }
}
