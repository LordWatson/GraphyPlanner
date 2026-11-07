<?php

namespace Tests\Feature\Api;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Api\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Outgoing webhooks: subscribing over the API, and what happens when a delivery is sent.
 *
 * The URL here is never called for real — `Http::fake()` intercepts everything.
 */
class OutgoingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private string $token;

    private string $writeToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->token = $this->issue(['read']);
        $this->writeToken = $this->issue(['read', 'write:drafts']);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issue(array $scopes): string
    {
        $plain = 'tok_'.bin2hex(random_bytes(8));

        McpToken::create([
            'org_id' => $this->org->id,
            'name' => 'test',
            'token_hash' => McpToken::hashToken($plain),
            'scopes' => $scopes,
        ]);

        return $plain;
    }

    /**
     * @return array<string, string>
     */
    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function endpoint(array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'org_id' => $this->org->id,
            'url' => 'https://hooks.example.test/alima',
            'secret' => 'secret-value',
            'events' => [WebhookEvent::PostApproved->value],
            ...$attributes,
        ]);
    }

    // --- subscribing over the API ------------------------------------------------------------

    public function test_an_integration_can_subscribe_itself(): void
    {
        // Over the API rather than only in the UI, because that is what a connector needs: it sets
        // its own subscription up when the user connects it.
        $response = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.test/new',
            'events' => [WebhookEvent::PostPublished->value],
            'description' => 'Our CRM',
        ], $this->auth($this->writeToken))->assertStatus(201);

        $this->assertSame('https://hooks.example.test/new', $response->json('data.url'));
        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertSame(1, WebhookEndpoint::query()->count());
    }

    public function test_the_secret_is_shown_once_and_never_again(): void
    {
        $created = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.test/new',
            'events' => [WebhookEvent::PostPublished->value],
        ], $this->auth($this->writeToken))->assertStatus(201);

        $secret = $created->json('data.secret');
        $id = $created->json('data.id');

        // Anyone holding it can forge a delivery the receiver will trust.
        $shown = $this->getJson("/api/v1/webhooks/{$id}", $this->auth())->assertOk();
        $this->assertNull($shown->json('data.secret'));
        $this->assertStringNotContainsString($secret, $shown->getContent());

        $listed = $this->getJson('/api/v1/webhooks', $this->auth())->assertOk();
        $this->assertStringNotContainsString($secret, $listed->getContent());
    }

    public function test_a_read_token_cannot_create_or_delete_a_subscription(): void
    {
        $endpoint = $this->endpoint();

        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.test/new',
            'events' => [WebhookEvent::PostPublished->value],
        ], $this->auth())->assertStatus(403);

        $this->deleteJson("/api/v1/webhooks/{$endpoint->id}", [], $this->auth())->assertStatus(403);
    }

    public function test_a_plaintext_url_or_unknown_event_is_refused(): void
    {
        $this->postJson('/api/v1/webhooks', [
            'url' => 'http://hooks.example.test/insecure',
            'events' => [WebhookEvent::PostPublished->value],
        ], $this->auth($this->writeToken))->assertJsonValidationErrors('url');

        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.test/new',
            'events' => ['post.invented'],
        ], $this->auth($this->writeToken))->assertJsonValidationErrors('events.0');
    }

    public function test_another_organizations_endpoint_is_not_reachable(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = WebhookEndpoint::create([
            'org_id' => $otherOrg->id,
            'url' => 'https://hooks.example.test/theirs',
            'secret' => 'theirs',
            'events' => [WebhookEvent::PostApproved->value],
        ]);

        $this->getJson("/api/v1/webhooks/{$theirs->id}", $this->auth())->assertNotFound();
        $this->deleteJson("/api/v1/webhooks/{$theirs->id}", [], $this->auth($this->writeToken))->assertNotFound();
    }

    // --- dispatch ----------------------------------------------------------------------------

    public function test_a_post_transition_queues_a_delivery_to_subscribed_endpoints_only(): void
    {
        Queue::fake();
        $wants = $this->endpoint(['events' => [WebhookEvent::PostApproved->value]]);
        $doesNot = $this->endpoint(['url' => 'https://hooks.example.test/other', 'events' => [WebhookEvent::OrderPaid->value]]);

        app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 1]);

        $this->assertSame(1, WebhookDelivery::query()->withoutOrgScope()->count());
        $this->assertSame($wants->id, WebhookDelivery::query()->withoutOrgScope()->value('webhook_endpoint_id'));
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_a_disabled_endpoint_receives_nothing(): void
    {
        Queue::fake();
        $this->endpoint(['disabled_at' => now(), 'disabled_reason' => 'too many failures']);

        $sent = app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 1]);

        $this->assertSame(0, $sent);
        Queue::assertNothingPushed();
    }

    /**
     * The event has to be emitted where the status actually changes, or an integration silently
     * misses transitions made by some other code path.
     */
    public function test_approving_a_post_emits_the_event_through_the_real_transition(): void
    {
        Queue::fake();
        $this->endpoint(['events' => [WebhookEvent::PostApproved->value]]);

        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::WaitingClient,
        ]);
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $owner);

        $delivery = WebhookDelivery::query()->withoutOrgScope()->firstOrFail();
        $this->assertSame(WebhookEvent::PostApproved->value, $delivery->event);
        $this->assertSame($post->id, $delivery->payload['data']['post_id']);
        // The moment it happened, so a retry hours later does not look like a fresh event.
        $this->assertArrayHasKey('occurred_at', $delivery->payload);
    }

    // --- delivery ----------------------------------------------------------------------------

    public function test_a_delivery_is_signed_the_way_we_verify_inbound_webhooks(): void
    {
        Http::fake();
        $endpoint = $this->endpoint();
        app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 7]);
        $delivery = WebhookDelivery::query()->withoutOrgScope()->firstOrFail();

        (new DeliverWebhookJob($delivery->id))->handle();

        Http::assertSent(function ($request) use ($endpoint) {
            $timestamp = $request->header('X-Alima-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", $endpoint->secret);

            return $request->url() === $endpoint->url
                && $request->header('X-Alima-Event')[0] === WebhookEvent::PostApproved->value
                && $request->header('X-Alima-Signature')[0] === $expected;
        });

        $delivery->refresh();
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_failing_endpoint_records_the_failure_and_does_not_mark_it_delivered(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $this->endpoint();
        app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 7]);
        $delivery = WebhookDelivery::query()->withoutOrgScope()->firstOrFail();

        (new DeliverWebhookJob($delivery->id))->handle();

        $delivery->refresh();
        $this->assertNull($delivery->delivered_at);
        $this->assertSame(500, $delivery->response_status);
        $this->assertStringContainsString('500', (string) $delivery->error);
    }

    public function test_an_endpoint_is_switched_off_after_too_many_failures(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $endpoint = $this->endpoint();

        // A URL that stopped existing months ago would otherwise retry every event forever.
        for ($i = 0; $i < WebhookEndpoint::FAILURES_BEFORE_DISABLE; $i++) {
            WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'org_id' => $this->org->id,
                'event' => WebhookEvent::PostApproved->value,
                'payload' => [],
                'failed_at' => now(),
            ]);
        }

        app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 7]);
        $pending = WebhookDelivery::query()->withoutOrgScope()->whereNull('failed_at')->firstOrFail();

        (new DeliverWebhookJob($pending->id))->handle();

        $endpoint->refresh();
        $this->assertFalse($endpoint->isActive());
        $this->assertStringContainsString('failed deliveries', (string) $endpoint->disabled_reason);
    }

    public function test_an_already_delivered_delivery_is_never_sent_twice(): void
    {
        Http::fake();
        // The test queue is sync, so without this the dispatcher's own job would deliver it before
        // this test could mark it delivered and try again.
        Queue::fake();
        $this->endpoint();
        app(WebhookDispatcher::class)->dispatch($this->org, WebhookEvent::PostApproved, ['post_id' => 7]);
        $delivery = WebhookDelivery::query()->withoutOrgScope()->firstOrFail();
        $delivery->update(['delivered_at' => now()]);

        (new DeliverWebhookJob($delivery->id))->handle();

        Http::assertNothingSent();
    }
}
