<?php

namespace App\Mcp\Tools;

use App\Enums\CampaignStatus;
use App\Enums\McpScope;
use App\Models\Campaign;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-2.4 — list the organization's campaigns as structured rows. Org-scoped explicitly plus
 * the bound member; billing fields (`billing_type`, `fee_amount`) only for members allowed to see a
 * client's billing. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('list_campaigns')]
#[Title('List campaigns')]
#[Description('Lists the organization\'s campaigns (id, client, name, code, status, objective, platforms, dates, goal). Filters: client_id, status, search. Read-only.')]
#[IsReadOnly]
class ListCampaignsTool extends McpTool
{
    private const LIMIT = 100;

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
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (CampaignStatus $s) => $s->value, CampaignStatus::cases()))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $campaigns = Campaign::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with('client:id,name')
            ->when(isset($validated['client_id']), fn (Builder $q) => $q->where('client_id', $validated['client_id']))
            ->when(! isset($validated['status']), fn (Builder $q) => $q->where('status', '!=', CampaignStatus::Archived->value))
            ->when(isset($validated['status']), fn (Builder $q) => $q->where('status', $validated['status']))
            ->when(isset($validated['search']), function (Builder $q) use ($validated): void {
                $like = '%'.addcslashes($validated['search'], '%_\\').'%';
                $q->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Campaign $campaign): array => McpPresenters::campaign(
                $campaign,
                $user !== null && $campaign->client !== null && $user->can('viewBilling', $campaign->client),
            ))
            ->all();

        return Response::structured([
            'organization' => $org->name,
            'count' => count($campaigns),
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('Only campaigns for this client.'),
            'status' => $schema->string()
                ->enum(array_map(fn (CampaignStatus $s) => $s->value, CampaignStatus::cases()))
                ->description('Only campaigns in this status. Omit to list all non-archived campaigns.'),
            'search' => $schema->string()->description('Match campaign name or code.'),
        ];
    }
}
