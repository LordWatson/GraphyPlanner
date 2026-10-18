<?php

namespace App\Support\Publishing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Step I-1.3 — timeouts and retries for every publishing-vendor HTTP call (Upload-Post, Meta
 * Graph), configured in `config/publishing.php` under `http`.
 *
 * - `forReads()`: status checks, health checks, music search, connect-link requests — safe to
 *   repeat, so they retry on 429, 5xx and connection errors.
 * - `forPublish()`: the upload POST. It only retries on 429 (the vendor explicitly refused it);
 *   a 5xx or a timeout may still have posted, and a double post is worse than a failed target the
 *   user can retry. The target's `publish_attempt_id` is sent as `Idempotency-Key` as well.
 *
 * Retries never throw (`throw: false`), so callers keep handling the final response themselves.
 */
final class VendorHttp
{
    public static function forReads(PendingRequest $request): PendingRequest
    {
        return self::withTimeouts($request)->retry(
            self::retries(),
            fn (int $attempt): int => self::backoffMs($attempt),
            function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            },
            throw: false,
        );
    }

    public static function forPublish(PendingRequest $request, ?string $idempotencyKey = null): PendingRequest
    {
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return self::withTimeouts($request)->retry(
            self::retries(),
            fn (int $attempt): int => self::backoffMs($attempt),
            fn (Throwable $exception): bool => $exception instanceof RequestException
                && $exception->response->status() === 429,
            throw: false,
        );
    }

    /**
     * Exponential backoff with full jitter on top: base, 2×base, 4×base … plus up to one base.
     */
    public static function backoffMs(int $attempt): int
    {
        $base = max(0, (int) config('publishing.http.retry_base_ms', 500));

        return $base * (2 ** max(0, $attempt - 1)) + ($base > 0 ? random_int(0, $base) : 0);
    }

    private static function withTimeouts(PendingRequest $request): PendingRequest
    {
        return $request
            ->timeout(max(1, (int) config('publishing.http.timeout', 30)))
            ->connectTimeout(max(1, (int) config('publishing.http.connect_timeout', 10)));
    }

    private static function retries(): int
    {
        // `retry()` counts total attempts, so N retries means N + 1 tries.
        return max(0, (int) config('publishing.http.retries', 2)) + 1;
    }
}
