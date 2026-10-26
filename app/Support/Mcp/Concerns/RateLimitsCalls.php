<?php

namespace App\Support\Mcp\Concerns;

use App\Support\Mcp\McpContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Response;

/**
 * Step MCP-1.4 — per-connection rate limiting / abuse guard for MCP tool calls. A runaway assistant
 * loop must not be able to hammer Graphy or the vendor APIs, so every call is counted against the
 * token (falling back to the org) and refused with a clean MCP error — never a 500 — once the
 * per-minute budget is spent.
 *
 * The budget comes from `config('mcp.rate_limit.per_minute')`; set it to 0 to disable. Only the
 * remote HTTP transport is limited (there is an `McpContext`); the trusted local/stdio transport is
 * never throttled. A rejection is logged at `warning` with org/token/tool context, never the secret.
 */
trait RateLimitsCalls
{
    /**
     * An MCP error `Response` if the connection is over its per-minute budget (and the attempt is
     * *not* counted or audited — it is already being throttled), or `null` to proceed. A successful
     * reservation counts one hit against the window.
     */
    protected function enforceRateLimit(McpContext $context): ?Response
    {
        $maxPerMinute = (int) config('mcp.rate_limit.per_minute', 60);

        if ($maxPerMinute <= 0) {
            return null; // limiting disabled
        }

        $key = 'mcp-call:'.($context->token?->id !== null ? 'token-'.$context->token->id : 'org-'.$context->organization->id);

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            $retryAfter = RateLimiter::availableIn($key);

            Log::warning('MCP call rejected: rate limit exceeded', [
                'org_id' => $context->organization->id,
                'mcp_token_id' => $context->token?->id,
                'tool' => $this->name(),
                'limit_per_minute' => $maxPerMinute,
                'retry_after' => $retryAfter,
            ]);

            return Response::error(sprintf(
                'Rate limit reached for this MCP connection (%d calls per minute). Try again in %d second(s).',
                $maxPerMinute,
                $retryAfter,
            ));
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
