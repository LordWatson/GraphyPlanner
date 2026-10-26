<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\RecordMcpCallAction;
use App\Enums\McpScope;
use App\Models\User;
use App\Support\Mcp\Concerns\AuthorizesScope;
use App\Support\Mcp\Concerns\RateLimitsCalls;
use App\Support\Mcp\McpContext;
use App\Support\Mcp\McpTenant;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * The base class every Graphy MCP tool extends. It makes the MCP-1 guardrails a framework concern
 * rather than something each tool must remember. On the remote HTTP transport every call, in order:
 *
 *   1. is rate-limited per connection (MCP-1.4) — refused cleanly once the budget is spent;
 *   2. is capability-scope gated (MCP-1.3) — refused before any Action runs if a scope is missing;
 *   3. is audited (MCP-1.5) — one `mcp_call_logs` row with the outcome + duration (never a secret).
 *
 * A tool declares the scope(s) it needs via `requiredScopes()` and puts its actual work in
 * `handleAuthorized()`. The populated `Request` is forwarded unchanged (arguments preserved); any
 * Actions/Services a tool needs are constructor-injected, since tools are resolved from the
 * container.
 *
 * The trusted local/stdio transport (and in-process tool tests) has no `McpContext`, so it is not
 * rate-limited, scope-gated or audited — it goes straight to the tool. On HTTP a tool is never
 * reached without a context (`AuthenticateMcp` 401s first). See .junie/plans/mcp-server.md.
 */
abstract class McpTool extends Tool
{
    use AuthorizesScope;
    use RateLimitsCalls;

    /**
     * The capability scope(s) a token must hold to call this tool — all of them (AND). Read-only
     * tools return `[McpScope::Read]`; write/schedule/AI tools their elevated scope.
     *
     * @return list<McpScope>
     */
    abstract protected function requiredScopes(): array;

    /**
     * Run the guardrails, then the tool. On the trusted local transport (no context) the tool runs
     * directly; on HTTP it is rate-limited, scope-gated and audited around the concrete handler.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $context = McpContext::current();

        if ($context === null) {
            return $this->handleAuthorized($request);
        }

        // Over budget: refuse cleanly and don't count/audit it — it is already being throttled.
        if (($limited = $this->enforceRateLimit($context)) !== null) {
            return $limited;
        }

        $startedAt = microtime(true);

        // Scope gate runs before the concrete handler, so an under-scoped token never hits an Action.
        $response = $this->denyUnlessScoped(...$this->requiredScopes()) ?? $this->handleAuthorized($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        app(RecordMcpCallAction::class)(
            $context,
            $this->name(),
            array_keys($request->toArray()),
            succeeded: ! ($response instanceof Response && $response->isError()),
            durationMs: $durationMs,
        );

        return $response;
    }

    /**
     * The tool's actual work, run only once the guardrails pass.
     */
    abstract protected function handleAuthorized(Request $request): Response|ResponseFactory;

    /**
     * Resolve the acting organization member and bind it onto the auth guard, so a tool can call the
     * exact same Actions/queries the web UI uses: `OrganizationScope` and policies read
     * `Auth::user()`, so once the member is bound every reused query scopes to the token's org
     * without the tool re-implementing tenancy (MCP-2). On HTTP the member is already bound by
     * `AuthenticateMcp`; locally (and in in-process tests with no member) it binds the dev org's
     * Owner. Returns null only when no member can be resolved (a misconfigured org with no Owner), so
     * the caller fails closed rather than running an unscoped query.
     */
    protected function actAsOrganizationMember(): ?User
    {
        $user = McpTenant::actingUser();

        if ($user === null) {
            return null;
        }

        if (! Auth::check()) {
            Auth::setUser($user);
        }

        return Auth::user();
    }
}
