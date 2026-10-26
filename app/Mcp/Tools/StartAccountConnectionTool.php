<?php

namespace App\Mcp\Tools;

use App\Actions\SocialAccounts\CreateConnectInviteAction;
use App\Enums\McpScope;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Phase MCP-4.2 — begin connecting a social account for a client. MCP never receives or stores any
 * vendor OAuth token: it only mints a tokenised, browser-completable self-connect invite (reusing
 * `CreateConnectInviteAction`) and returns the human-completable URL. The raw invite token stays
 * server-side (only its hash is persisted) and is never returned to the model — the secrets-guard
 * test asserts this tool's output carries no token. Requires the elevated `WriteSchedule` scope.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('start_account_connection')]
#[Title('Start account connection')]
#[Description('Creates a browser-completable link a human opens to connect a social account for a client. MCP never receives or stores any vendor token — it only returns the connect URL. Requires the write:schedule scope.')]
class StartAccountConnectionTool extends McpTool
{
    public function __construct(private readonly CreateConnectInviteAction $createInvite) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::WriteSchedule];
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
            'client_id' => ['required', 'integer'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:40'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        if ($user->cannot('createConnectInvite', [SocialAccount::class, $client])) {
            return Response::error('You are not allowed to connect accounts for this client.');
        }

        $result = ($this->createInvite)($client, $user, [
            'platforms' => $validated['platforms'] ?? null,
            'expires_in_days' => $validated['expires_in_days'] ?? null,
        ]);

        // Return the human-completable URL only — never the raw token ($result['token']).
        return Response::structured([
            'client_id' => $client->id,
            'connect_url' => $result['url'],
            'message' => 'Send this link to someone who can complete the connection in a browser. It expires and is single-use.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client to connect an account for.'),
            'platforms' => $schema->array()->description('Optional list of platforms to pre-select (e.g. instagram, tiktok).'),
            'expires_in_days' => $schema->integer()->description('How many days the link stays valid (1–30).'),
        ];
    }
}
