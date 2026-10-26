<?php

namespace App\Actions\Mcp;

use App\Models\McpCallLog;
use App\Support\Mcp\McpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step MCP-1.5 — the single write path for an `mcp_call_logs` audit row. It is bookkeeping that must
 * never turn a working tool call into an error: the insert is wrapped in `DB::transaction()` and the
 * whole method swallows its own failures (log-and-continue), exactly like `RecordAiUsageAction`.
 *
 * It also emits the per-call log line at the right level (CLAUDE.md #6): `info` on success,
 * `warning` on a failed call. Only the argument *keys* are recorded/logged — never a value, the
 * token or any secret. If the audit insert itself throws, that is logged at `error` and the call
 * still returns normally.
 */
class RecordMcpCallAction
{
    /**
     * @param  list<string>  $argumentKeys  the names of the arguments the tool was called with
     */
    public function __invoke(McpContext $context, string $tool, array $argumentKeys, bool $succeeded, int $durationMs): void
    {
        $outcome = $succeeded ? 'success' : 'error';

        $logContext = [
            'org_id' => $context->organization->id,
            'mcp_token_id' => $context->token?->id,
            'tool' => $tool,
            'argument_keys' => $argumentKeys,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
        ];

        // The call outcome drives the level; the secret/token value is never in the context.
        $succeeded
            ? Log::info('MCP tool call', $logContext)
            : Log::warning('MCP tool call failed', $logContext);

        try {
            DB::transaction(fn () => McpCallLog::create([
                'org_id' => $context->organization->id,
                'mcp_token_id' => $context->token?->id,
                'tool' => $tool,
                'argument_keys' => $argumentKeys,
                'outcome' => $outcome,
                'duration_ms' => $durationMs,
            ]));
        } catch (Throwable $e) {
            Log::error('Failed to record MCP call', [
                ...$logContext,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
