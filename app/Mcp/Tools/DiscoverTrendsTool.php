<?php

namespace App\Mcp\Tools;

use App\Actions\AI\DiscoverTrendsAction;
use App\Enums\McpScope;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Services\AI\AiAccess;
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
 * Phase MCP-5.1 — surface on-brand content trends for a client, reusing `DiscoverTrendsAction` with
 * all of its guardrails, caching, and `AiUsageGuard` budget enforcement intact. Banned phrases are
 * already dropped inside the Action, so none can pass through here. Requires the `Ai` scope, respects
 * the org's AI-enable flag, and turns an over-budget signal into a clean, non-technical MCP error.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('discover_trends')]
#[Title('Discover trends')]
#[Description('Returns AI-suggested, on-brand content trends for a client. Respects the org\'s AI enablement and monthly token budget. Requires the ai scope.')]
#[IsReadOnly]
class DiscoverTrendsTool extends McpTool
{
    public function __construct(
        private readonly DiscoverTrendsAction $discoverTrends,
        private readonly AiAccess $aiAccess,
    ) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Ai];
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

        if (! $this->aiAccess->canUse($user)) {
            return Response::error('AI assistance is not enabled for this organization.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:40'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        $platforms = $validated['platforms'] ?? $client->socialAccounts()
            ->withoutOrgScope()
            ->get()
            ->map(fn (SocialAccount $account): string => $account->platform->value)
            ->unique()
            ->values()
            ->all();

        try {
            $trends = ($this->discoverTrends)($client, $platforms);
        } catch (AiBudgetExceededException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'client_id' => $client->id,
            'trends' => $trends->jsonSerialize(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client to discover trends for.'),
            'platforms' => $schema->array()->description('Platforms to focus on (e.g. instagram, tiktok). Defaults to the client\'s connected platforms.'),
        ];
    }
}
