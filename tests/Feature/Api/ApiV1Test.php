<?php

namespace Tests\Feature\Api;

use App\Enums\McpScope;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public REST API's v1 surface.
 *
 * The thing worth proving over and over here is tenancy: the organization comes from the token and
 * never from request input, so there is no parameter a caller can send that widens what they see.
 * Everything else — shapes, filters, pagination — is a contract other people's code will depend on,
 * which is why it is pinned rather than left to drift.
 */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private string $token;

    private Organization $otherOrg;

    private Client $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->org, $this->client, $this->token] = $this->tenant('Mine');
        [$this->otherOrg, $this->otherClient] = $this->tenant('Theirs');
    }

    /**
     * @return array{0: Organization, 1: Client, 2: string}
     */
    private function tenant(string $name, array $scopes = ['read']): array
    {
        $org = Organization::factory()->create(['name' => $name]);
        $client = Client::factory()->for($org, 'organization')->create(['name' => $name.' brand']);
        User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $plain = 'tok_'.$name.'_'.bin2hex(random_bytes(8));
        McpToken::create([
            'org_id' => $org->id,
            'name' => $name.' token',
            'token_hash' => McpToken::hashToken($plain),
            'scopes' => $scopes,
        ]);

        return [$org, $client, $plain];
    }

    /**
     * @return array<string, string>
     */
    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    // --- authentication ----------------------------------------------------------------------

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_an_unknown_or_revoked_token_is_refused(): void
    {
        $this->getJson('/api/v1/me', $this->auth('tok_nonsense'))->assertStatus(401);

        $revoked = McpToken::query()->firstOrFail();
        $revoked->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/me', $this->auth())->assertStatus(401);
    }

    public function test_me_reports_the_organization_and_scopes(): void
    {
        $this->getJson('/api/v1/me', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->org->id)
            ->assertJsonPath('data.organization.name', 'Mine')
            ->assertJsonPath('data.scopes.0.scope', 'read')
            ->assertJsonPath('data.scopes.0.label', McpScope::Read->label());
    }

    public function test_a_token_without_the_scope_is_refused_with_a_usable_message(): void
    {
        [, , $scopeless] = $this->tenant('Scopeless', []);

        $this->getJson('/api/v1/clients', $this->auth($scopeless))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonFragment(['message' => 'This endpoint needs the "read" scope. Read agency data']);
    }

    /**
     * The API must not depend on the MCP switch: an organization may want one without the other.
     */
    public function test_the_api_works_while_mcp_is_switched_off(): void
    {
        $this->org->update(['mcp_enabled' => false]);

        $this->getJson('/api/v1/me', $this->auth())->assertOk();
    }

    // --- tenancy -----------------------------------------------------------------------------

    public function test_clients_lists_only_the_tokens_own_organization(): void
    {
        $response = $this->getJson('/api/v1/clients', $this->auth())->assertOk();

        $this->assertSame([$this->client->id], $response->json('data.*.id'));
    }

    public function test_another_organizations_record_is_not_reachable_by_id(): void
    {
        // Nothing a caller can send widens the org: it comes from the token.
        $this->getJson("/api/v1/clients/{$this->otherClient->id}", $this->auth())->assertForbidden();
    }

    public function test_posts_list_only_the_tokens_own_organization(): void
    {
        $mine = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        Post::factory()->for($this->otherClient)->create(['org_id' => $this->otherOrg->id, 'status' => PostStatus::Draft]);

        $response = $this->getJson('/api/v1/posts', $this->auth())->assertOk();

        $this->assertSame([$mine->id], $response->json('data.*.id'));
    }

    // --- shape and filters -------------------------------------------------------------------

    public function test_a_post_carries_its_channels(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::LinkedIn,
            'handle' => 'mine',
        ]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => '2026-12-01',
            'scheduled_local_time' => '09:00',
        ]);

        // Channels inline, because "where did this go and did it work" is the question being asked
        // and making the caller fetch them separately would be N+1 over HTTP.
        $this->getJson("/api/v1/posts/{$post->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.channels.0.platform', 'linkedin')
            ->assertJsonPath('data.channels.0.handle', 'mine')
            ->assertJsonPath('data.channels.0.scheduled_local_time', '09:00');
    }

    public function test_archived_posts_stay_out_unless_asked_for_by_name(): void
    {
        Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Archived]);

        $this->getJson('/api/v1/posts', $this->auth())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/posts?status=archived', $this->auth())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_updated_since_makes_an_incremental_pull_possible(): void
    {
        $old = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        $old->forceFill(['updated_at' => now()->subWeek()])->save();
        $fresh = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $response = $this->getJson(
            '/api/v1/posts?updated_since='.urlencode(now()->subDay()->toIso8601String()),
            $this->auth(),
        )->assertOk();

        $this->assertSame([$fresh->id], $response->json('data.*.id'));
    }

    /**
     * A bad parameter must be a 422 naming it, never a stack trace. `?updated_since=garbage`
     * reached `Carbon::parse` and came back as a 500 until this was validated.
     */
    public function test_a_malformed_filter_is_a_422_not_a_500(): void
    {
        $this->getJson('/api/v1/posts?updated_since=not-a-date', $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('updated_since');

        $this->getJson('/api/v1/posts?status=not-a-status', $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_lists_are_cursor_paginated_and_the_last_page_says_so(): void
    {
        Post::factory()->count(3)->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $first = $this->getJson('/api/v1/posts?per_page=2', $this->auth())->assertOk();
        $first->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson('/api/v1/posts?per_page=2&cursor='.$cursor, $this->auth())->assertOk();
        $second->assertJsonCount(1, 'data');

        // Null is the only end-of-list signal a client needs.
        $this->assertNull($second->json('meta.next_cursor'));
    }

    /**
     * Asking for more than the maximum is told so, rather than silently given something smaller.
     * A caller who thinks they asked for 10,000 and quietly received 200 will write a sync that
     * misses rows and never find out why.
     */
    public function test_asking_for_too_large_a_page_is_refused_with_the_limit(): void
    {
        $this->getJson('/api/v1/posts?per_page=10000', $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->getJson('/api/v1/posts?per_page=200', $this->auth())
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_social_accounts_report_connection_status_without_leaking_credentials(): void
    {
        SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'handle' => 'mine',
        ]);
        $this->org->update(['upload_post_key' => 'up-secret-key']);

        $response = $this->getJson('/api/v1/social-accounts', $this->auth())->assertOk();

        $response->assertJsonPath('data.0.platform', 'instagram');
        $this->assertNotNull($response->json('data.0.connection_status'));
        // An API response is a wider audience than a settings page.
        $this->assertStringNotContainsString('up-secret-key', $response->getContent());
    }
}
