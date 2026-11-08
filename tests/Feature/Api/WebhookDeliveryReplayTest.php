<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Client;
use App\Models\McpToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Item 18 step 5 — the dead-letter list and its replay.
 *
 * `WebhookDispatcher` has recorded every attempt since phase five, but the customer could not see
 * any of it, so a missed event was unrecoverable without asking us. What is pinned here is that
 * the two questions have answers — "did it arrive, and if not why" and "send it again" — and that
 * replay refuses the two cases where it would quietly do nothing useful: a delivery still working
 * through its own retries, and an endpoint that has been switched off.
 */
class WebhookDeliveryReplayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private string $readToken;

    private string $writeToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Client::factory()->for($this->org, 'organization')->create();
        User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->readToken = $this->issue(['read']);
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
        return ['Authorization' => 'Bearer '.($token ?? $this->readToken)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'org_id' => $this->org->id,
            'event' => WebhookEvent::PostApproved->value,
            'payload' => ['event' => WebhookEvent::PostApproved->value, 'data' => ['post_id' => 7]],
            ...$attributes,
        ]);
    }

    // --- the list ----------------------------------------------------------------------------

    public function test_it_lists_attempts_newest_first_with_why_they_failed(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, ['delivered_at' => now()->subHour(), 'attempts' => 1, 'response_status' => 200]);
        $failed = $this->delivery($endpoint, [
            'failed_at' => now(), 'attempts' => 5, 'response_status' => 500, 'error' => 'Server error',
        ]);

        $response = $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries", $this->auth());

        $response->assertOk()
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.attempts', 5)
            ->assertJsonPath('data.0.error', 'Server error')
            // The payload is included so a receiver can reprocess without a replay at all.
            ->assertJsonPath('data.0.payload.data.post_id', 7)
            ->assertJsonPath('data.1.status', 'delivered');
    }

    public function test_the_list_can_be_filtered_to_what_still_needs_attention(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, ['delivered_at' => now()]);
        $failed = $this->delivery($endpoint, ['failed_at' => now()]);
        $this->delivery($endpoint);

        $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries?status=failed", $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id);

        $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries?status=pending", $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_an_unknown_filter_is_refused_rather_than_ignored(): void
    {
        $endpoint = $this->endpoint();

        // Silently ignoring it would show a full list to a caller who asked for a subset.
        $this->getJson("/api/v1/webhooks/{$endpoint->id}/deliveries?status=everything", $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_another_organizations_deliveries_are_not_reachable(): void
    {
        $otherOrg = Organization::factory()->create();
        $theirs = WebhookEndpoint::create([
            'org_id' => $otherOrg->id,
            'url' => 'https://hooks.example.test/theirs',
            'secret' => 'their-secret',
            'events' => [WebhookEvent::PostApproved->value],
        ]);

        $this->getJson("/api/v1/webhooks/{$theirs->id}/deliveries", $this->auth())->assertNotFound();
    }

    // --- replay ------------------------------------------------------------------------------

    public function test_a_failed_delivery_can_be_replayed(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, [
            'failed_at' => now(), 'attempts' => 5, 'response_status' => 500, 'error' => 'Server error',
        ]);

        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertOk()->assertJsonPath('data.status', 'pending');

        $delivery->refresh();
        $this->assertTrue($delivery->isPending());
        $this->assertNull($delivery->error);
        // Cumulative on purpose: it answers "how many times have we tried", and a replay is
        // another try rather than a fresh start.
        $this->assertSame(5, $delivery->attempts);

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_the_original_payload_is_replayed_unchanged(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, [
            'failed_at' => now(),
            'payload' => ['event' => 'post.approved', 'occurred_at' => '2026-01-01T00:00:00+00:00', 'data' => ['post_id' => 7]],
        ]);

        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertOk();

        // `occurred_at` is when it happened, not when it was resent — rewriting it would turn a
        // replay into a claim that the event just occurred.
        $this->assertSame('2026-01-01T00:00:00+00:00', $delivery->refresh()->payload['occurred_at']);
    }

    public function test_a_pending_delivery_cannot_be_replayed(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint);

        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertStatus(409)->assertJsonPath('error.code', 'already_pending');

        Queue::assertNothingPushed();
    }

    public function test_a_replay_into_a_disabled_endpoint_is_refused_with_the_fix(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint(['disabled_at' => now(), 'disabled_reason' => 'Too many failures']);
        $delivery = $this->delivery($endpoint, ['failed_at' => now()]);

        // Without this the job would run and immediately fail with "the endpoint was switched
        // off", burning the replay and telling the caller nothing useful.
        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertStatus(409)->assertJsonPath('error.code', 'endpoint_disabled');

        Queue::assertNothingPushed();
    }

    public function test_a_read_token_cannot_replay(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, ['failed_at' => now()]);

        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth(),
        )->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');

        Queue::assertNothingPushed();
    }

    public function test_a_delivery_belonging_to_another_endpoint_is_not_found(): void
    {
        $mine = $this->endpoint();
        $other = $this->endpoint(['url' => 'https://hooks.example.test/second']);
        $delivery = $this->delivery($other, ['failed_at' => now()]);

        $this->postJson(
            "/api/v1/webhooks/{$mine->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertNotFound();
    }

    // --- re-enabling ------------------------------------------------------------------------

    public function test_a_disabled_endpoint_can_be_switched_back_on_and_then_replayed(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint(['disabled_at' => now(), 'disabled_reason' => 'Too many failures']);
        $delivery = $this->delivery($endpoint, ['failed_at' => now()]);

        $this->postJson("/api/v1/webhooks/{$endpoint->id}/enable", [], $this->auth($this->writeToken))
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $endpoint->refresh();
        $this->assertTrue($endpoint->isActive());
        $this->assertNull($endpoint->disabled_reason);

        // The recovery path the kept deliveries exist for: fix the URL, switch it on, replay.
        $this->postJson(
            "/api/v1/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/replay",
            [],
            $this->auth($this->writeToken),
        )->assertOk();

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_a_read_token_cannot_re_enable_an_endpoint(): void
    {
        $endpoint = $this->endpoint(['disabled_at' => now()]);

        $this->postJson("/api/v1/webhooks/{$endpoint->id}/enable", [], $this->auth())
            ->assertForbidden();

        $this->assertFalse($endpoint->refresh()->isActive());
    }
}
