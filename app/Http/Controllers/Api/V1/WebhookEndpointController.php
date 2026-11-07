<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiResponse;
use App\Support\Mcp\McpContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Managing outgoing webhook subscriptions over the API.
 *
 * Over the API rather than only in the UI because that is what a connector needs: a Zapier-style
 * integration sets up its own subscription when the user connects it, and tears it down when they
 * disconnect. Requiring a person to paste a URL into a settings page is what makes those
 * integrations impossible to build.
 *
 * The secret is returned **once**, by `store()`, and never again — `WebhookEndpoint` hides it. A
 * caller that loses it rotates the endpoint by creating a new one, which is also the only safe
 * answer: anyone holding the secret can forge a delivery the receiver will trust.
 */
class WebhookEndpointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginate(
            $request,
            WebhookEndpoint::query()->orderBy('id'),
            fn (WebhookEndpoint $endpoint) => self::present($endpoint),
        );
    }

    public function show(WebhookEndpoint $endpoint): JsonResponse
    {
        // `OrganizationScope` already pins the org for lists; binding lifts it so this is the check
        // that another organization's endpoint is not readable by id.
        abort_unless($endpoint->org_id === $this->currentOrgId(), 404);

        return ApiResponse::item(self::present($endpoint));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // https only: the delivery carries the signature *and* the data, and a plaintext hop
            // would hand both to anyone on the path.
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::values())],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // 64 hex characters from a cryptographic source. The receiver's only defence against a
        // forged delivery, so it is not a guessable string.
        $secret = bin2hex(random_bytes(32));

        $endpoint = WebhookEndpoint::query()->create([
            'org_id' => $this->currentOrgId(),
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'data' => self::present($endpoint) + [
                // Shown once. Said plainly, because a caller that does not store it now cannot
                // verify a single delivery later.
                'secret' => $secret,
                'secret_notice' => 'Store this now — it is never shown again. Verify deliveries with '
                    .'HMAC-SHA256 over "{X-Alima-Timestamp}.{raw body}" and compare to X-Alima-Signature.',
            ],
        ], 201);
    }

    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        abort_unless($endpoint->org_id === $this->currentOrgId(), 404);

        // Deliveries cascade with it. An endpoint being removed means the integration is gone, so
        // keeping its history would be keeping somebody else's payloads for no one.
        $endpoint->delete();

        return response()->json(null, 204);
    }

    /**
     * The organization the bearer token resolved to. Never request input.
     */
    private function currentOrgId(): int
    {
        return (int) McpContext::current()?->organization->id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'description' => $endpoint->description,
            'active' => $endpoint->isActive(),
            'disabled_reason' => $endpoint->disabled_reason,
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }
}
