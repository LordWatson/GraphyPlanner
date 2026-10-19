<?php

namespace Tests\Feature;

use App\Enums\ClientHealth;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Mail\ClientConnectInviteMail;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ClientConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-2.8 — a client connects their own accounts from a link (or the portal), without sharing
 * a password, and only ever their own client's accounts.
 */
class ConnectInviteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    private SocialAccount $instagram;

    private SocialAccount $tiktok;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Http::fake([
            '*/uploadposts/users' => Http::response(['success' => true]),
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://connect.upload-post.test/abc', 'duration' => '48h']),
        ]);

        $this->org = Organization::factory()->create(['name' => 'Studio North', 'upload_post_key' => 'key']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Saffron Kitchen', 'owner_user_id' => $this->owner->id]);
        $this->instagram = $this->account($this->client, Platform::Instagram, ConnectionStatus::TokenExpired);
        $this->tiktok = $this->account($this->client, Platform::TikTok, ConnectionStatus::NotConnected);
    }

    private function account(Client $client, Platform $platform, ConnectionStatus $status): SocialAccount
    {
        return SocialAccount::factory()->for($client)->create([
            'org_id' => $client->org_id,
            'platform' => $platform,
            'handle' => '@'.$platform->value,
            'connection_status' => $status,
        ]);
    }

    /**
     * @return array{0: string, 1: ConnectInvite} the raw token from the link, and the invite
     */
    private function invite(array $data = []): array
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('clients.connect-invites.store', $this->client), $data)
            ->assertCreated();

        $token = basename((string) $response->json('url'));

        return [$token, ConnectInvite::query()->findOrFail($response->json('invite.id'))];
    }

    public function test_the_token_is_shown_once_emailed_once_and_only_its_hash_is_stored(): void
    {
        [$token, $invite] = $this->invite(['email' => 'owner@saffron.test']);

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertDatabaseMissing('connect_invites', ['token_hash' => $token]);
        $this->assertSame(7, (int) round(now()->diffInDays($invite->expires_at)));

        Mail::assertQueued(ClientConnectInviteMail::class, fn (ClientConnectInviteMail $mail) => $mail->hasTo('owner@saffron.test')
            && $mail->connectUrl === route('connect.show', $token));

        $this->actingAs($this->owner)->get(route('clients.show', $this->client))
            ->assertInertia(fn ($page) => $page->has('connectInvites', 1)->missing('connectInvites.0.token_hash')->missing('connectInvites.0.url'));
    }

    public function test_only_owners_and_strategists_can_create_links_and_only_for_their_own_clients_accounts(): void
    {
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $this->actingAs($designer)->postJson(route('clients.connect-invites.store', $this->client))->assertForbidden();

        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $foreign = $this->account($otherClient, Platform::Instagram, ConnectionStatus::NotConnected);

        $this->actingAs($this->owner)
            ->postJson(route('clients.connect-invites.store', $this->client), ['social_account_ids' => [$foreign->id]])
            ->assertJsonValidationErrors('social_account_ids.0');

        $otherOrg = Organization::factory()->create();
        $stranger = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $this->actingAs($stranger)->postJson(route('clients.connect-invites.store', $this->client))->assertForbidden();
    }

    public function test_the_public_page_lists_only_the_slots_the_link_covers(): void
    {
        [$token] = $this->invite(['platforms' => ['tiktok']]);

        $this->get(route('connect.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('connect/show')
                ->where('client.name', 'Saffron Kitchen')
                ->where('agency', 'Studio North')
                ->has('accounts', 1)
                ->where('accounts.0.platform', 'tiktok')
                ->missing('accounts.0.external_profile_id'));
    }

    public function test_an_expired_or_revoked_link_stops_working(): void
    {
        [$token, $invite] = $this->invite();

        $invite->update(['expires_at' => now()->subMinute()]);
        $this->get(route('connect.show', $token))->assertStatus(410);
        $this->postJson(route('connect.account', [$token, $this->instagram]))->assertStatus(410);

        [$token, $invite] = $this->invite();
        $this->actingAs($this->owner)->delete(route('connect-invites.destroy', $invite))->assertRedirect();
        $this->assertNotNull($invite->refresh()->revoked_at);
        $this->get(route('connect.show', $token))->assertStatus(410);
        $this->postJson(route('connect.account', [$token, $this->instagram]))->assertStatus(410);
    }

    public function test_a_link_starts_the_vendor_flow_for_its_own_slots_and_nothing_else(): void
    {
        [$token] = $this->invite(['social_account_ids' => [$this->instagram->id]]);
        auth()->logout();

        $this->postJson(route('connect.account', [$token, $this->instagram]))
            ->assertOk()
            ->assertJson(['url' => 'https://connect.upload-post.test/abc']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/generate-jwt') && $request['platforms'] === ['instagram']);

        // A slot the link wasn't issued for, another client's account, another org's account.
        $this->postJson(route('connect.account', [$token, $this->tiktok]))->assertNotFound();
        $otherClientAccount = $this->account(Client::factory()->for($this->org, 'organization')->create(), Platform::Instagram, ConnectionStatus::NotConnected);
        $this->postJson(route('connect.account', [$token, $otherClientAccount]))->assertNotFound();
        $otherOrgAccount = $this->account(Client::factory()->for(Organization::factory()->create(), 'organization')->create(), Platform::Instagram, ConnectionStatus::NotConnected);
        $this->postJson(route('connect.account', [$token, $otherOrgAccount]))->assertNotFound();
    }

    public function test_finishing_the_connection_records_it_notifies_the_owner_and_clears_the_red_health(): void
    {
        $this->client->refresh();
        $this->assertSame(ClientHealth::Red, $this->client->health_status, 'The expired token makes the client Red.');

        [$token, $invite] = $this->invite();
        $this->postJson(route('connect.account', [$token, $this->instagram]))->assertOk();

        $redirect = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/generate-jwt'))['redirect_url'];
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $this->get(route('social-accounts.callback', [...$query, 'profile' => 'saffron']))
            ->assertOk()
            ->assertDontSee('Go back to AlimaPlanner');

        $this->assertSame(ConnectionStatus::Connected, $this->instagram->refresh()->connection_status);
        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame([$this->instagram->id], $invite->connected_account_ids);
        Notification::assertSentTo($this->owner, ClientConnectedAccount::class);
        $this->assertNotSame(ClientHealth::Red, $this->client->refresh()->health_status);
    }

    public function test_the_portal_panel_shows_the_clients_own_slots_and_connects_only_them(): void
    {
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);

        $this->actingAs($reviewer)->get(route('portal.dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('connectAccounts', 2)
                ->missing('connectAccounts.0.timezone')
                ->missing('connectAccounts.0.persona_override'));

        $this->actingAs($reviewer)->postJson(route('portal.accounts.connect', $this->instagram))->assertOk()->assertJsonPath('url', 'https://connect.upload-post.test/abc');

        $foreign = $this->account(Client::factory()->for($this->org, 'organization')->create(), Platform::Instagram, ConnectionStatus::NotConnected);
        $this->actingAs($reviewer)->postJson(route('portal.accounts.connect', $foreign))->assertForbidden();

        // Staff don't use the portal route (the portal middleware turns them away).
        $this->actingAs($this->owner)->postJson(route('portal.accounts.connect', $this->instagram))->assertStatus(403);
    }
}
