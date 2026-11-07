<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one webhook delivery, and records what happened.
 *
 * Signed the way we *verify* inbound webhooks, because that is the scheme we already know is right:
 * `X-Alima-Signature: sha256=<hex>` over `HMAC_SHA256(secret, "{timestamp}.{raw body}")`, with the
 * timestamp in its own header so a receiver can reject a replay. Audit finding 4 is the reason it
 * is spelled out rather than improvised: a webhook is only as good as the receiver's ability to
 * tell a real one from a forged one, and that is impossible unless the signed material is exactly
 * defined.
 *
 * Retries are the queue's, with growing backoff. After `FAILURES_BEFORE_DISABLE` consecutive
 * failures the endpoint is switched off with a reason: a URL that stopped existing months ago
 * would otherwise keep a worker busy retrying every event forever, and nobody would be told. The
 * deliveries are kept either way, so fixing the URL and replaying them is possible.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId)
    {
        // The webhooks queue, so somebody else's slow endpoint never sits in front of a publish.
        $this->onQueue(Queues::WEBHOOKS);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Minutes, roughly: a receiver that is briefly down should not need a human.
        return [10, 60, 300, 1800];
    }

    public function handle(): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->withoutOrgScope()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || ! $delivery->isPending()) {
            // Already delivered, already given up on, or gone. Nothing to do and nothing to log.
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->isActive()) {
            $delivery->update(['failed_at' => now(), 'error' => 'The endpoint was removed or switched off.']);

            return;
        }

        $body = (string) json_encode($delivery->payload);
        $timestamp = (string) now()->getTimestamp();

        $delivery->increment('attempts');

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Alima-Event' => $delivery->event,
                    'X-Alima-Delivery' => (string) $delivery->id,
                    'X-Alima-Timestamp' => $timestamp,
                    'X-Alima-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $endpoint->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $e) {
            $this->recordFailure($delivery, $endpoint, null, $e->getMessage());

            throw $e;
        }

        if ($response->successful()) {
            $delivery->update([
                'delivered_at' => now(),
                'response_status' => $response->status(),
                'error' => null,
            ]);

            return;
        }

        $this->recordFailure($delivery, $endpoint, $response->status(), 'The endpoint answered '.$response->status().'.');

        // Non-2xx is a failure the queue should retry, so this job must not end successfully.
        $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
    }

    /**
     * Out of retries. Mark the delivery failed so it shows in the dead-letter list rather than
     * sitting "pending" forever.
     */
    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()->withoutOrgScope()->whereKey($this->deliveryId)->whereNull('delivered_at')->update([
            'failed_at' => now(),
            'error' => 'Gave up after repeated failures: '.($exception?->getMessage() ?? 'unknown error'),
        ]);
    }

    private function recordFailure(WebhookDelivery $delivery, WebhookEndpoint $endpoint, ?int $status, string $error): void
    {
        $delivery->update(['response_status' => $status, 'error' => $error]);

        Log::warning('Webhook delivery failed', [
            'org_id' => $delivery->org_id,
            'endpoint_id' => $endpoint->id,
            'delivery_id' => $delivery->id,
            'event' => $delivery->event,
            'status' => $status,
        ]);

        // Consecutive failures, counted from the deliveries themselves rather than a column that
        // would need resetting on every success.
        $recentFailures = WebhookDelivery::query()
            ->withoutOrgScope()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->whereNotNull('failed_at')
            ->where('failed_at', '>=', now()->subDays(7))
            ->count();

        if ($recentFailures >= WebhookEndpoint::FAILURES_BEFORE_DISABLE) {
            $endpoint->update([
                'disabled_at' => now(),
                'disabled_reason' => sprintf('Switched off after %d failed deliveries in a week.', $recentFailures),
            ]);

            Log::warning('Webhook endpoint disabled after repeated failures', [
                'org_id' => $delivery->org_id,
                'endpoint_id' => $endpoint->id,
            ]);
        }
    }
}
