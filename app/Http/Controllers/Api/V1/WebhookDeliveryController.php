<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiResponse;
use App\Support\Mcp\McpContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The dead-letter list and its replay button (item 18 step 5).
 *
 * `WebhookDispatcher` has always recorded a row per attempt, and `DeliverWebhookJob` has always
 * retried and then given up — but until now the customer could not see any of it. The first
 * question about a webhook is "did it arrive, and if not why", and the second is "send it again".
 * Those are the two endpoints here.
 *
 * Over the API rather than only in the UI for the same reason the subscriptions are: the system
 * that missed an event is the one that should be able to ask for it again, without a person.
 */
class WebhookDeliveryController extends Controller
{
    public function index(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->ensureOurs($endpoint);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,delivered,failed'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ApiResponse::MAX_PER_PAGE],
        ]);

        $query = $endpoint->deliveries()->getQuery()
            ->when($request->string('status')->value() === 'delivered', fn ($q) => $q->whereNotNull('delivered_at'))
            ->when($request->string('status')->value() === 'failed', fn ($q) => $q->whereNotNull('failed_at'))
            ->when(
                $request->string('status')->value() === 'pending',
                fn ($q) => $q->whereNull('delivered_at')->whereNull('failed_at'),
            )
            // Newest first: a dead-letter list is read from the top.
            ->orderByDesc('id');

        return ApiResponse::paginate($request, $query, fn (WebhookDelivery $delivery) => self::present($delivery));
    }

    /**
     * Queue a failed delivery again, with its original payload.
     *
     * The payload is replayed byte for byte, including its `occurred_at` — the receiver is being
     * told about something that happened then, not now, and rewriting the timestamp would turn a
     * replay into a lie.
     */
    public function replay(WebhookEndpoint $endpoint, WebhookDelivery $delivery): JsonResponse
    {
        $this->ensureOurs($endpoint);

        abort_unless($delivery->webhook_endpoint_id === $endpoint->id, 404);

        // Replaying into a switched-off endpoint would be answered by the job with "the endpoint
        // was switched off", burning the replay. Say so here instead, and name the fix.
        if (! $endpoint->isActive()) {
            return ApiResponse::error(
                'endpoint_disabled',
                'This endpoint is switched off, so a replay would fail immediately. Re-enable it with '
                .'POST /webhooks/{endpoint}/enable once the URL works again, then replay.',
                409,
            );
        }

        if ($delivery->isPending()) {
            return ApiResponse::error(
                'already_pending',
                'This delivery has not finished its own attempts yet. Wait for it to succeed or fail.',
                409,
            );
        }

        // `attempts` keeps counting rather than resetting: it answers "how many times have we
        // tried to deliver this", and a replay is another try, not a fresh start.
        $delivery->forceFill(['delivered_at' => null, 'failed_at' => null, 'error' => null])->save();

        DeliverWebhookJob::dispatch($delivery->id);

        Log::info('Webhook delivery replayed', [
            'org_id' => $delivery->org_id,
            'endpoint_id' => $endpoint->id,
            'delivery_id' => $delivery->id,
            'event' => $delivery->event,
        ]);

        return ApiResponse::item(self::present($delivery->refresh()));
    }

    /**
     * Switch a disabled endpoint back on.
     *
     * Needed because the auto-disable after `FAILURES_BEFORE_DISABLE` consecutive failures was a
     * one-way door: the only recovery was creating a new endpoint, which mints a new secret and
     * means reconfiguring the receiver. The kept deliveries are useless without a way back on.
     */
    public function enable(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->ensureOurs($endpoint);

        $endpoint->forceFill(['disabled_at' => null, 'disabled_reason' => null])->save();

        Log::info('Webhook endpoint re-enabled', ['org_id' => $endpoint->org_id, 'endpoint_id' => $endpoint->id]);

        return ApiResponse::item([
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'active' => true,
        ]);
    }

    /**
     * Route binding lifts `OrganizationScope`, so this is the check that another organization's
     * endpoint is not reachable by id. 404, not 403: whether it exists is not this token's
     * business.
     */
    private function ensureOurs(WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->org_id === (int) McpContext::current()?->organization->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event' => $delivery->event,
            'status' => match (true) {
                $delivery->delivered_at !== null => 'delivered',
                $delivery->failed_at !== null => 'failed',
                default => 'pending',
            },
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'error' => $delivery->error,
            // The payload as sent, so a receiver can reprocess it without a replay at all.
            'payload' => $delivery->payload,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
