<?php

namespace Tests\Feature\Api;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Middleware\IdempotentApiRequest;
use App\Models\ApiIdempotencyKey;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 18 step 2 — the write half of the public API.
 *
 * Three promises are worth pinning, because other people's code will lean on them and because
 * each one is a way the product could be undermined by an integration:
 *
 *  - **A write stops at a draft.** Nothing can be created already scheduled, nothing past draft
 *    can be edited, and scheduling needs its own scope and a passing checklist. An API that let a
 *    token skip approval would make the approval workflow decorative.
 *  - **Scopes are enforced per endpoint**, so a read-only token cannot write and a drafting token
 *    cannot schedule.
 *  - **A retried write does the work once.** That is the whole point of the idempotency key, and
 *    the failure it prevents — a duplicate post from a timeout — is invisible until it happens to
 *    a customer.
 */
class ApiV1WritesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private string $draftsToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'Mine']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->draftsToken = $this->token(['read', 'write:drafts']);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function token(array $scopes): string
    {
        $plain = 'tok_'.bin2hex(random_bytes(8));

        McpToken::create([
            'org_id' => $this->org->id,
            'name' => implode(',', $scopes).' token',
            'token_hash' => McpToken::hashToken($plain),
            'scopes' => $scopes,
        ]);

        return $plain;
    }

    /**
     * @return array<string, string>
     */
    private function auth(?string $token = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->draftsToken)];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    // --- creating a draft --------------------------------------------------------------------

    public function test_it_creates_a_draft_with_its_channels(): void
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
        ]);

        $response = $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'caption' => 'Hello from the API',
            'hashtags' => ['launch'],
            'channels' => [[
                'social_account_id' => $account->id,
                'scheduled_local_date' => now()->addWeek()->toDateString(),
                'scheduled_local_time' => '09:30',
            ]],
        ], $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.status', PostStatus::Draft->value)
            ->assertJsonPath('data.caption', 'Hello from the API')
            ->assertJsonPath('data.channels.0.social_account_id', $account->id)
            ->assertJsonPath('data.channels.0.scheduled_local_time', '09:30');

        $this->assertDatabaseHas('posts', [
            'id' => $response->json('data.id'),
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft->value,
        ]);
    }

    public function test_a_status_cannot_be_smuggled_in_to_skip_approval(): void
    {
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'caption' => 'Sneaky',
            'status' => PostStatus::Approved->value,
        ], $this->auth())
            ->assertCreated()
            // The field is not in the validated set, so it never reaches CreatePostAction.
            ->assertJsonPath('data.status', PostStatus::Draft->value);
    }

    public function test_another_clients_campaign_cannot_be_borrowed(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $campaign = Campaign::factory()->for($otherClient)->create(['org_id' => $otherOrg->id]);

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
            'campaign_id' => $campaign->id,
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('campaign_id');
    }

    public function test_a_client_in_another_organization_is_not_found(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();

        $this->postJson('/api/v1/posts', [
            'client_id' => $otherClient->id,
            'caption' => 'Not mine',
        ], $this->auth())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_a_read_only_token_cannot_create(): void
    {
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id,
        ], $this->auth($this->token(['read'])))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    // --- editing a draft ---------------------------------------------------------------------

    public function test_it_edits_only_the_fields_sent(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
            'master_caption' => 'Before',
            'hashtags' => ['keep'],
        ]);

        $this->patchJson("/api/v1/posts/{$post->id}", ['caption' => 'After'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.caption', 'After');

        // Untouched, because it was not in the request.
        $this->assertSame(['keep'], $post->refresh()->hashtags);
    }

    public function test_an_approved_post_is_not_editable_over_the_api(): void
    {
        // `PostPolicy::update` would allow this — the web editor can edit an approved post. The
        // API deliberately stops at draft, so an integration cannot rewrite what a client has
        // already signed off.
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
            'master_caption' => 'Signed off',
        ]);

        $this->patchJson("/api/v1/posts/{$post->id}", ['caption' => 'Changed'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'not_editable');

        $this->assertSame('Signed off', $post->refresh()->master_caption);
    }

    public function test_a_scheduled_post_is_refused_by_the_policy(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Scheduled,
            'master_caption' => 'Locked',
        ]);

        // 403, not our 409: the policy is the authority and it refuses first.
        $this->patchJson("/api/v1/posts/{$post->id}", ['caption' => 'Changed'], $this->auth())
            ->assertForbidden();

        $this->assertSame('Locked', $post->refresh()->master_caption);
    }

    public function test_an_empty_edit_is_refused_rather_than_silently_doing_nothing(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        $this->patchJson("/api/v1/posts/{$post->id}", [], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'nothing_to_update');
    }

    public function test_assets_replace_the_attachment_list_in_the_order_given(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);
        $first = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $second = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->patchJson("/api/v1/posts/{$post->id}", [
            'asset_ids' => [$second->id, $first->id],
        ], $this->auth())->assertOk();

        $this->assertSame(
            [$second->id, $first->id],
            $post->refresh()->assets()->pluck('assets.id')->all(),
        );
    }

    // --- scheduling --------------------------------------------------------------------------

    public function test_a_drafting_token_cannot_schedule(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
        ]);

        $this->postJson("/api/v1/posts/{$post->id}/schedule", [], $this->auth())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_an_unapproved_post_cannot_be_scheduled(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        $this->postJson("/api/v1/posts/{$post->id}/schedule", [], $this->auth($this->token(['read', 'write:schedule'])))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_transition');
    }

    public function test_a_failing_checklist_blocks_scheduling_and_names_what_is_outstanding(): void
    {
        // Approved but with no channel at all, so the checklist cannot pass.
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
            'master_caption' => 'Ready?',
        ]);

        $response = $this->postJson(
            "/api/v1/posts/{$post->id}/schedule",
            [],
            $this->auth($this->token(['read', 'write:schedule'])),
        );

        $response->assertStatus(409)->assertJsonPath('error.code', 'checklist_failed');
        $this->assertNotEmpty($response->json('error.outstanding'));
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    // --- idempotency -------------------------------------------------------------------------

    public function test_a_retried_create_returns_the_first_response_and_creates_one_post(): void
    {
        $payload = ['client_id' => $this->client->id, 'caption' => 'Only once'];

        $first = $this->postJson('/api/v1/posts', $payload, $this->auth(idempotencyKey: 'key-1'));
        $first->assertCreated();

        $second = $this->postJson('/api/v1/posts', $payload, $this->auth(idempotencyKey: 'key-1'));

        $second->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('posts', 1);
    }

    public function test_reusing_a_key_for_a_different_request_is_refused(): void
    {
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'First',
        ], $this->auth(idempotencyKey: 'key-2'))->assertCreated();

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'Different',
        ], $this->auth(idempotencyKey: 'key-2'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertDatabaseCount('posts', 1);
    }

    public function test_a_failed_write_does_not_burn_its_key(): void
    {
        // Invalid: no client_id. The corrected retry must be allowed to run.
        $this->postJson('/api/v1/posts', ['caption' => 'Oops'], $this->auth(idempotencyKey: 'key-3'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertDatabaseCount('api_idempotency_keys', 0);

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'Oops',
        ], $this->auth(idempotencyKey: 'key-3'))->assertCreated();
    }

    public function test_a_key_is_scoped_to_its_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $plain = 'tok_'.bin2hex(random_bytes(8));
        McpToken::create([
            'org_id' => $otherOrg->id,
            'name' => 'Theirs',
            'token_hash' => McpToken::hashToken($plain),
            'scopes' => ['read', 'write:drafts'],
        ]);

        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'Mine',
        ], $this->auth(idempotencyKey: 'shared-key'))->assertCreated();

        // The same key from another organization is a different key, not a replay of ours.
        $this->postJson('/api/v1/posts', [
            'client_id' => $otherClient->id, 'caption' => 'Theirs',
        ], $this->auth($plain, 'shared-key'))->assertCreated();

        $this->assertDatabaseCount('posts', 2);
    }

    public function test_a_write_without_a_key_still_works(): void
    {
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'No key',
        ], $this->auth())->assertCreated();

        $this->assertDatabaseCount('api_idempotency_keys', 0);
    }

    public function test_keys_are_pruned_past_the_retention_window(): void
    {
        $this->postJson('/api/v1/posts', [
            'client_id' => $this->client->id, 'caption' => 'Recent',
        ], $this->auth(idempotencyKey: 'fresh'))->assertCreated();

        $stale = ApiIdempotencyKey::query()->create([
            'org_id' => $this->org->id,
            'key' => 'stale',
            'endpoint' => 'POST api/v1/posts',
            'request_hash' => str_repeat('a', 64),
            'response_status' => 201,
            'response_body' => ['data' => ['id' => 1]],
        ]);
        $stale->forceFill(['created_at' => now()->subHours(IdempotentApiRequest::RETENTION_HOURS + 1)])->save();

        $this->artisan('api:prune-idempotency-keys')->assertExitCode(0);

        // The fresh key still answers a retry; the stale one is gone with its stored body.
        $this->assertDatabaseMissing('api_idempotency_keys', ['key' => 'stale']);
        $this->assertDatabaseHas('api_idempotency_keys', ['key' => 'fresh']);
    }
}
