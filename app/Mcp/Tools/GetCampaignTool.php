<?php

namespace App\Mcp\Tools;

use App\Actions\Campaigns\GetCampaignOverviewAction;
use App\Enums\McpScope;
use App\Models\Campaign;
use App\Support\Mcp\McpPresenters;
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
 * Phase MCP-2.4 — one campaign's profile plus its overview (launch checklist, status funnel, next
 * upcoming posts and brief status), reusing `GetCampaignOverviewAction` — the same data the campaign
 * workspace header shows. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_campaign')]
#[Title('Get campaign')]
#[Description('Returns one campaign\'s profile plus its launch checklist, status funnel, upcoming posts and brief status. Read-only.')]
#[IsReadOnly]
class GetCampaignTool extends McpTool
{
    public function __construct(private readonly GetCampaignOverviewAction $overview) {}

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

        $validated = $request->validate([
            'campaign_id' => ['required', 'integer'],
        ]);

        $campaign = Campaign::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with('client:id,name')
            ->where('id', $validated['campaign_id'])
            ->first();

        if ($campaign === null) {
            return Response::error('No campaign with that id exists in this organization.');
        }

        $canViewBilling = $user !== null && $campaign->client !== null && $user->can('viewBilling', $campaign->client);

        return Response::structured([
            ...McpPresenters::campaign($campaign, $canViewBilling),
            'overview' => ($this->overview)($campaign),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required()->description('The id of the campaign to fetch.'),
        ];
    }
}
