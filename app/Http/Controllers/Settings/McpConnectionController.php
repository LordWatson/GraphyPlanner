<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Mcp\CreateMcpTokenAction;
use App\Actions\Mcp\GetMcpUsageAction;
use App\Actions\Mcp\RevokeMcpTokenAction;
use App\Enums\McpScope;
use App\Http\Controllers\Controller;
use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step MCP-6.1 / MCP-7.1 / MCP-7.2 — the Owner-only "AI connections" settings page. It lists and
 * revokes the org's MCP bearer tokens, mints new ones with a chosen scope set (read-only by default;
 * publishing/AI are an explicit opt-in with warnings in the UI), shows the audit trail + usage
 * (MCP-7.1) and the per-org master switch (MCP-7.2), and surfaces the connection URL assistants point
 * at. Owner-only via `OrganizationPolicy::update`; the route group re-confirms the password because
 * the page issues secrets. The thin controller validates and delegates to Actions (CLAUDE.md #1).
 */
class McpConnectionController extends Controller
{
    public function edit(Request $request, GetMcpUsageAction $usage): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/ai-connections', [
            'tokens' => McpToken::query()
                ->withoutGlobalScopes()
                ->where('org_id', $organization->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (McpToken $token): array => $this->presentToken($token))
                ->all(),
            'scopes' => array_map(fn (McpScope $scope): array => [
                'value' => $scope->value,
                'label' => $scope->label(),
                'default' => in_array($scope, McpScope::default(), true),
            ], McpScope::cases()),
            'usage' => $usage($organization),
            'settings' => [
                'mcp_enabled' => $organization->mcp_enabled,
            ],
            // The per-env master switch: while off (the production default) the /mcp route 404s for
            // everyone, so the UI can explain that the connection URL won't work yet.
            'httpEnabled' => (bool) config('mcp.http.enabled', false),
            // Hosted assistants (Claude web/desktop, ChatGPT) only accept an https connector URL, so
            // always present https here even when the app itself runs on http locally. A local http
            // endpoint is reachable only via the stdio transport or an https tunnel — see the docs.
            'connectionUrl' => secure_url(config('mcp.http.path', 'mcp')),
            // Shown exactly once, straight after minting — never stored or re-shown.
            'newToken' => $request->session()->get('newMcpToken'),
        ]);
    }

    public function store(Request $request, CreateMcpTokenAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_map(fn (McpScope $s) => $s->value, McpScope::cases()))],
        ]);

        $result = $action($organization, $validated['name'], $validated['scopes'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection token created. Copy it now — it won\'t be shown again.')]);

        return to_route('mcp-connections.edit')->with('newMcpToken', $result['token']);
    }

    public function destroy(Request $request, McpToken $mcpToken, RevokeMcpTokenAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        // McpToken has no org global scope (lookup is by hash pre-auth), so guard the binding here.
        abort_unless($mcpToken->org_id === $organization->id, 404);

        $action($mcpToken, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection revoked.')]);

        return to_route('mcp-connections.edit');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $validated = $request->validate([
            'mcp_enabled' => ['required', 'boolean'],
        ]);

        $organization->forceFill(['mcp_enabled' => $validated['mcp_enabled']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['mcp_enabled']
            ? __('MCP is on. Connected assistants can now reach this organization.')
            : __('MCP is off. Every connection for this organization is now rejected.')]);

        return to_route('mcp-connections.edit');
    }

    /**
     * The safe, owner-facing shape of a token. Never the hash (it's `#[Hidden]`) or any secret.
     *
     * @return array<string, mixed>
     */
    private function presentToken(McpToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'scopes' => $token->scopes,
            'scope_labels' => array_map(
                fn (string $scope): string => McpScope::tryFrom($scope)?->label() ?? $scope,
                $token->scopes,
            ),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'revoked' => $token->revoked_at !== null,
        ];
    }
}
