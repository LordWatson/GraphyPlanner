<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ConnectStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 1.9.3 — the additive Facebook Login connect flow that grants Meta Graph API access for
 * Instagram audio search (Step 1.9.4), without changing how the account is connected for
 * publishing via Upload-Post (Step 1.3).
 */
class FacebookInstagramConnectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_sends_the_browser_to_facebooks_oauth_dialog(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);

        $response = $this->actingAs($owner)->get(route('social-accounts.facebook.connect', [$client, $account]));

        $response->assertRedirect();
        $this->assertStringContainsString('facebook.com', $response->headers->get('Location'));
    }

    public function test_callback_exchanges_the_code_and_persists_the_meta_grant_on_the_account(): void
    {
        config(['services.facebook.client_id' => 'app-id', 'services.facebook.client_secret' => 'app-secret']);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);

        Http::fake([
            '*/oauth/access_token*grant_type=fb_exchange_token*' => Http::response([
                'access_token' => 'long-lived-token',
                'expires_in' => 5184000,
            ], 200),
            '*/oauth/access_token*' => Http::response([
                'access_token' => 'short-lived-token',
            ], 200),
            '*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1']],
            ], 200),
            '*/page-1*' => Http::response([
                'instagram_business_account' => ['id' => '17841400000000001'],
            ], 200),
        ]);

        $response = $this->actingAs($owner)->get(route('social-accounts.facebook.callback', [$client, $account]).'?code=auth-code&state='.$this->metaState($account, $owner));

        $response->assertRedirect(route('clients.show', $client));

        $account->refresh();
        $this->assertSame('long-lived-token', $account->meta_access_token);
        $this->assertSame('17841400000000001', $account->meta_instagram_user_id);
        $this->assertNotNull($account->meta_access_token_expires_at);
    }

    public function test_static_meta_callback_resolves_the_account_from_state_and_persists_the_grant(): void
    {
        config(['services.facebook.client_id' => 'app-id', 'services.facebook.client_secret' => 'app-secret']);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);

        Http::fake([
            '*/oauth/access_token*grant_type=fb_exchange_token*' => Http::response([
                'access_token' => 'long-lived-token',
                'expires_in' => 5184000,
            ], 200),
            '*/oauth/access_token*' => Http::response([
                'access_token' => 'short-lived-token',
            ], 200),
            '*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1']],
            ], 200),
            '*/page-1*' => Http::response([
                'instagram_business_account' => ['id' => '17841400000000001'],
            ], 200),
        ]);

        $response = $this->actingAs($owner)->get(
            route('auth.meta.callback').'?code=auth-code&state='.$this->metaState($account, $owner)
        );

        $response->assertRedirect(route('clients.show', $client));

        $account->refresh();
        $this->assertSame('long-lived-token', $account->meta_access_token);
        $this->assertSame('17841400000000001', $account->meta_instagram_user_id);
    }

    public function test_callback_fails_gracefully_when_graph_base_url_is_misconfigured(): void
    {
        config([
            'services.facebook.client_id' => 'app-id',
            'services.facebook.client_secret' => 'app-secret',
            'services.facebook.graph_base_url' => 'https://graph.facebook.co',
        ]);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);

        $response = $this->actingAs($owner)->get(route('social-accounts.facebook.callback', [$client, $account]).'?code=auth-code&state='.$this->metaState($account, $owner));

        $response->assertRedirect(route('clients.show', $client));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('facebook.com', session('error'));

        $account->refresh();
        $this->assertNull($account->meta_access_token);
    }

    public function test_a_designer_cannot_start_the_connect_flow(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);

        $response = $this->actingAs($designer)->get(route('social-accounts.facebook.connect', [$client, $account]));

        $response->assertForbidden();
    }

    public function test_a_callback_with_a_forged_or_reused_state_is_rejected(): void
    {
        config(['services.facebook.client_id' => 'app-id', 'services.facebook.client_secret' => 'app-secret']);
        Http::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::Instagram]);

        // The old, guessable state (the raw account id) no longer works on either callback.
        $this->actingAs($owner)
            ->get(route('auth.meta.callback').'?code=auth-code&state='.$account->id)
            ->assertRedirect(route('dashboard'));
        $this->actingAs($owner)
            ->get(route('social-accounts.facebook.callback', [$client, $account]).'?code=auth-code&state=forged')
            ->assertSessionHas('error');

        // A state issued to someone else is rejected too.
        $teammate = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $this->actingAs($owner)
            ->get(route('auth.meta.callback').'?code=auth-code&state='.$this->metaState($account, $teammate))
            ->assertRedirect(route('dashboard'));

        Http::assertNothingSent();
        $this->assertNull($account->fresh()->meta_access_token);
    }

    private function metaState(SocialAccount $account, User $user): string
    {
        return app(ConnectStateStore::class)->issue($account, $user, 'meta');
    }
}
