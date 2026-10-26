<?php

namespace App\Actions\Mcp;

use App\Models\McpCallLog;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Step MCP-7.1 — the MCP usage + audit summary an Owner sees on the "AI connections" settings page:
 * how many calls their connected assistants made, which tools, how many failed, and a recent audit
 * trail. All read-only aggregation over `mcp_call_logs`, strictly scoped to the one org. It never
 * exposes an argument value or a token (only the audited tool name, outcome and timing).
 */
class GetMcpUsageAction
{
    private const WINDOW_DAYS = 30;

    private const RECENT_LIMIT = 25;

    /**
     * @return array{window_days: int, totals: array{calls: int, errors: int}, by_tool: list<array{tool: string, calls: int, errors: int}>, recent: list<array{tool: string, outcome: string, duration_ms: int|null, token: string|null, at: string|null}>}
     */
    public function __invoke(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays(self::WINDOW_DAYS);

        // Columns are qualified because `recent` joins `mcp_tokens` (which also has an `org_id`).
        $base = McpCallLog::query()
            ->withoutGlobalScopes()
            ->where('mcp_call_logs.org_id', $organization->id)
            ->where('mcp_call_logs.created_at', '>=', $since);

        // `toBase()` so the aggregates come back as plain stdClass rows (not McpCallLog models with
        // unknown select aliases), and `array_values` so the results are lists.
        $byTool = array_values((clone $base)->toBase()
            ->select('tool')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(CASE WHEN outcome = 'error' THEN 1 ELSE 0 END) as errors")
            ->groupBy('tool')
            ->orderByDesc('calls')
            ->get()
            ->map(fn (object $row): array => [
                'tool' => (string) $row->tool,
                'calls' => (int) $row->calls,
                'errors' => (int) $row->errors,
            ])
            ->all());

        $recent = array_values((clone $base)->toBase()
            ->leftJoin('mcp_tokens', 'mcp_tokens.id', '=', 'mcp_call_logs.mcp_token_id')
            ->orderByDesc('mcp_call_logs.id')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'mcp_call_logs.tool',
                'mcp_call_logs.outcome',
                'mcp_call_logs.duration_ms',
                'mcp_call_logs.created_at',
                'mcp_tokens.name as token_name',
            ])
            ->map(fn (object $row): array => [
                'tool' => (string) $row->tool,
                'outcome' => (string) $row->outcome,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'token' => $row->token_name === null ? null : (string) $row->token_name,
                'at' => $row->created_at === null ? null : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ])
            ->all());

        return [
            'window_days' => self::WINDOW_DAYS,
            'totals' => [
                'calls' => (int) (clone $base)->toBase()->count(),
                'errors' => (int) (clone $base)->toBase()->where('outcome', 'error')->count(),
            ],
            'by_tool' => $byTool,
            'recent' => $recent,
        ];
    }
}
