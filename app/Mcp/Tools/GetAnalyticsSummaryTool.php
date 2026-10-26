<?php

namespace App\Mcp\Tools;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Enums\McpScope;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-2.4 — the organization analytics summary (clients, posts, approvals, team, revenue, AI,
 * engagement), reusing `GetOrganizationAnalyticsSummaryAction`. Role scoping is preserved: sections
 * the acting member may not see (revenue, AI cost) come back null exactly as in the web dashboard,
 * because the action receives the resolved member as the viewer. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_analytics_summary')]
#[Title('Get analytics summary')]
#[Description('Returns the organization\'s analytics summary (clients, posts, approvals, team, revenue, AI, engagement) for a date range. Revenue/AI-cost sections are omitted for members not allowed to see them. Optional preset (last_7_days, last_30_days, this_month, …) or from/to, and client_id/campaign_id filters. Read-only.')]
#[IsReadOnly]
class GetAnalyticsSummaryTool extends McpTool
{
    public function __construct(private readonly GetOrganizationAnalyticsSummaryAction $summary) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Read];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        $user = $this->actAsOrganizationMember();

        if ($user === null) {
            return Response::error('No member is available to authorize this connection.');
        }

        $validated = $request->validate([
            'preset' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
        ]);

        $filters = OrganizationAnalyticsFilters::fromArray([
            'preset' => $validated['preset'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'client_ids' => isset($validated['client_id']) ? [$validated['client_id']] : [],
            'campaign_ids' => isset($validated['campaign_id']) ? [$validated['campaign_id']] : [],
        ], $org);

        return Response::structured(($this->summary)($org, $filters, $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()->description('A named range such as last_7_days, last_30_days or this_month. Ignored if from/to are given.'),
            'from' => $schema->string()->format('date')->description('Custom range start (YYYY-MM-DD).'),
            'to' => $schema->string()->format('date')->description('Custom range end (YYYY-MM-DD).'),
            'client_id' => $schema->integer()->description('Drill into a single client.'),
            'campaign_id' => $schema->integer()->description('Limit to a single campaign.'),
        ];
    }
}
