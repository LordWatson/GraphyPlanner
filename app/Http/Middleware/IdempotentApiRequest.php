<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Models\Scopes\OrganizationScope;
use App\Support\Api\ApiResponse;
use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write to the public REST API safe to retry (item 18 step 2).
 *
 * A client whose request times out cannot tell whether we did the work. Its only sane move is to
 * retry, and without this that retry creates a second post. With an `Idempotency-Key` header, the
 * retry gets the first response back instead.
 *
 * **Optional, not required.** A required header would be the stricter design, but it would also
 * reject every first `curl` anybody tries, and a caller that does not retry does not need one. The
 * docs say to send one; this honours it when it is there.
 *
 * Three states, decided by the row rather than by a lock:
 *
 *  - **no row** — reserve it, run the request, record the response;
 *  - **row with a response** — hand that response back with `Idempotency-Replayed: true`, without
 *    running anything;
 *  - **row with no response yet** — another request with this key is still in flight (or died
 *    mid-write). Answer `409`, because the one thing we must not do is run the work twice.
 *
 * Reserved *before* the work, so two concurrent retries collide on the unique index instead of
 * both being allowed through — the ordering audit finding 2 is about.
 *
 * Only successful responses are stored. A `422` is the client's to fix and its corrected retry
 * should be allowed to run, so the reservation is released instead.
 */
class IdempotentApiRequest
{
    /**
     * How long a key is honoured. Long enough to cover any retry a sane client makes, short enough
     * that the table stays small; `api:prune-idempotency-keys` deletes rows past it.
     */
    public const RETENTION_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return ApiResponse::error(
                'invalid_idempotency_key',
                'An Idempotency-Key must be 255 characters or fewer. A UUID is a good choice.',
                400,
            );
        }

        $orgId = (int) McpContext::current()?->organization->id;
        $endpoint = $request->method().' '.$request->path();
        $hash = hash('sha256', (string) json_encode($request->all()));

        $existing = ApiIdempotencyKey::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('org_id', $orgId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $endpoint, $hash, $orgId, $key);
        }

        try {
            $reservation = ApiIdempotencyKey::query()->create([
                'org_id' => $orgId,
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
            ]);
        } catch (QueryException $e) {
            // Lost the race to an identical request that arrived microseconds earlier. Treating it
            // as in-flight is the safe answer: the caller retries and gets the stored response.
            Log::info('API idempotency key raced', ['org_id' => $orgId, 'endpoint' => $endpoint]);

            return $this->inFlight();
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            // Let the corrected retry through rather than pinning the client to its own mistake.
            $reservation->delete();

            return $response;
        }

        $reservation->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_body' => json_decode((string) $response->getContent(), true),
        ])->save();

        return $response;
    }

    /**
     * A key we have seen before: the same request replays, a different one is refused.
     */
    private function replay(ApiIdempotencyKey $existing, string $endpoint, string $hash, int $orgId, string $key): Response
    {
        if ($existing->endpoint !== $endpoint || $existing->request_hash !== $hash) {
            Log::warning('API idempotency key reused for a different request', [
                'org_id' => $orgId,
                'endpoint' => $endpoint,
                'first_used_on' => $existing->endpoint,
            ]);

            return ApiResponse::error(
                'idempotency_key_reused',
                'This Idempotency-Key was already used for a different request. Use a new key per operation.',
                409,
            );
        }

        if ($existing->isInFlight()) {
            return $this->inFlight();
        }

        return response()
            ->json($existing->response_body, (int) $existing->response_status)
            ->header('Idempotency-Replayed', 'true');
    }

    private function inFlight(): Response
    {
        return ApiResponse::error(
            'idempotency_key_in_flight',
            'A request with this Idempotency-Key is still being processed. Retry in a moment with the same key.',
            409,
        );
    }
}
