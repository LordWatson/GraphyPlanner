# MCP Server

> ✅ **Security: Phase MCP-1 complete (the backbone).** The remote Streamable HTTP transport
> (`POST /mcp`) is a **bearer-token-authenticated, strictly org-scoped** resource server:
> unauthenticated/invalid requests are rejected 401 before any tool runs (MCP-1.2); a valid token
> resolves to **exactly one organization** (two-org isolation test green); tools are
> **capability-scope gated** (MCP-1.3), **rate-limited per connection** (MCP-1.4) and **audited**
> (MCP-1.5), and an automated **secrets-exposure guard** (MCP-1.6) covers every registered primitive.
> It still ships **OFF by default** (`mcp.http.enabled` / `MCP_HTTP_ENABLED`) — a deployment choice,
> not a missing guardrail — and while off (including production) every verb on the route 404s. The
> local/stdio transport and in-process tool tests remain unauthenticated and fall back to a single
> hardcoded "dev org" (`McpDevOrg`) for local dev only (so they are not rate-limited or audited).
> See `.junie/plans/mcp-server.md`.

## Purpose

Graphy *is* an MCP (Model Context Protocol) server: it exposes Graphy's data and actions so AI
assistants (Claude Desktop/web, ChatGPT, Cursor, anything that speaks MCP) can drive the agency on
the user's behalf. This module covers Phase **MCP-0** — the thinnest possible working server with one
read-only tool and one resource, driven from a real assistant, to learn MCP hands-on.

The guiding idea (CLAUDE.md principle #3): an MCP tool is *just another thin entry point into the
same business logic the web UI already uses.* A tool handler validates input, calls the same
`App\Actions\*`/model query, and maps the result to MCP output — it never reimplements business
rules. (In MCP-0 the "query reuse" is a simple org-scoped model lookup; MCP-2 wires the tools onto
the existing `ListClientsAction`/presenters once auth exists.)

## MCP primitives we expose (MCP-0)

| Primitive | Name | Class | What it does | Annotations |
|---|---|---|---|---|
| Tool | `list_clients` | `App\Mcp\Tools\ListClientsTool` | Lists the resolved org's clients (`id`, `name`, `status`) as structured content; no arguments. Org comes from `McpTenant` (authenticated token's org on HTTP; dev org locally). Requires the `Read` scope. | `#[IsReadOnly]` |
| Resource | `brand_brain` | `App\Mcp\Resources\BrandBrainResource` | Returns the resolved org's first client's Brand Brain as markdown, reusing `BrandBrain::toGrokContext()`. | `text/markdown`, URI `graphy://brand-brain` |
| Prompt | — | — | None yet (Phase MCP-5). | — |

## Models / Tables

- **`McpToken` (`mcp_tokens`)** — MCP-1.2. A manually-issued bearer token for a remote MCP
  connection. Columns: `org_id` (FK, the single tenant the token resolves to), `user_id` (nullable
  FK, the actor the token is attributed to for audit), `name`, `token_hash` (unique SHA-256 — the
  plain token is never stored), `scopes` (json, capability set; default `['read']`), `last_used_at`,
  `revoked_at` (soft revoke, keeps audit history), timestamps. **Not** org-scoped as a global scope:
  lookup by hash happens pre-auth, so it must query across orgs by hash (mirrors `PortalLoginToken`/
  `ReviewToken`). `token_hash` is `#[Hidden]`.
- **`McpCallLog` (`mcp_call_logs`)** — MCP-1.5. One audit row per executed MCP tool call. Columns:
  `org_id` (FK), `mcp_token_id` (nullable FK, `nullOnDelete` so history survives a token delete),
  `tool` (name), `argument_keys` (json — the argument *names* only, never values), `outcome`
  (`success`|`error`), `duration_ms`, timestamps. Uses `BelongsToOrganization` for parity with
  `AiUsageLog`; on the MCP transport there is no signed-in member so `OrganizationScope` is a no-op
  and the explicit `org_id` on write scopes it. **No column can carry a secret, token or argument
  value** (the MCP-1.6 guard asserts this).

## Actions / Services / Contracts

- `App\Mcp\Servers\GraphyServer` (extends `Laravel\Mcp\Server`): the server. Name `Graphy`, version
  `0.0.1`, with `#[Instructions]` describing the agency domain. Registers the tool and resource.
- `App\Support\Mcp\McpDevOrg`: resolves the single spike "dev org". Order: configured
  `mcp.spike.dev_org_id` → the seeded `graphy-agency` org → the lowest-id org → `null`. A deliberate
  stand-in for real tenancy; deleted/replaced by token→org resolution in MCP-1.
- `App\Actions\Mcp\CreateMcpTokenAction` (MCP-1.2): mints a bearer token for an org — generates the
  plain token, stores only its hash, returns `['token' => plain, 'model' => McpToken]` (the plain
  token is the return value only). Defaults (when `$scopes` is `null`) to `McpScope::defaultValues()`
  — the read-only set (MCP-1.3); logs org/user/token-id + scopes, never the token. Single write (no
  transaction, CLAUDE.md #5). Reused by the MCP-6.1 settings page later.
- `App\Enums\McpScope` (MCP-1.3): the capability scopes a token can carry — `Read` (`read`),
  `WriteDrafts` (`write:drafts`), `WriteSchedule` (`write:schedule`), `Ai` (`ai`), `Inbox` (`inbox`).
  The backing strings are the stable wire format stored in `mcp_tokens.scopes` and compared in
  `McpContext::hasScope()`. `McpScope::default()`/`defaultValues()` is the single source of truth for
  a new token's read-only default. `label()` gives the owner-facing description for the MCP-6.1 page.
- `App\Support\Mcp\Concerns\AuthorizesScope` (MCP-1.3): the scope gate. `denyUnlessScoped(McpScope
  ...$required)` returns an MCP error `Response` (naming the missing scope) or `null`. All listed
  scopes are required (AND). No `McpContext` (local/stdio + in-process tests) = trusted transport =
  `null` (no-op), mirroring `McpTenant`'s dev-org fallback. Rejections log at `warning` with
  org/token/tool context, never the secret. A scope is *necessary, not sufficient* — the tool's
  Action still enforces tenancy/policy/checklist on top.
- `App\Mcp\Tools\McpTool` (MCP-1.3 → MCP-1.5): the base class every Graphy MCP tool extends (uses
  `AuthorizesScope` + `RateLimitsCalls`). A tool declares `requiredScopes(): list<McpScope>` and puts
  its work in `handleAuthorized()`. On the HTTP transport `handle()` runs the guardrails in order —
  **(1)** rate limit, **(2)** scope gate, **(3)** audit around the handler — so an over-budget call is
  refused before anything, an under-scoped token never reaches any Action, and every executed call
  writes one audit row. The populated `Request` is forwarded unchanged; Actions/Services are
  constructor-injected (tools resolve from the container). With no `McpContext` (trusted local/stdio
  + tests) it goes straight to `handleAuthorized()` — no limiting/audit.
- `App\Support\Mcp\Concerns\RateLimitsCalls` (MCP-1.4): `enforceRateLimit(McpContext)` counts the
  call against a one-minute `RateLimiter` window keyed per token (falling back to org), returning a
  clean MCP error `Response` ("Rate limit reached…") when the budget is spent, else `null` (and one
  hit recorded). Budget from `config('mcp.rate_limit.per_minute')` (0 disables). An over-budget call
  is neither counted again nor audited — it is already being throttled. Rejections log at `warning`.
- `App\Actions\Mcp\RecordMcpCallAction` (MCP-1.5): the single write path for an `mcp_call_logs` row.
  Mirrors `RecordAiUsageAction`: wrapped in `DB::transaction()` and **never throws** (log-and-continue
  on its own failure). Records `org_id`, token id, `tool`, argument *keys*, outcome and duration; logs
  the call at `info` (success) / `warning` (failed call), and at `error` only if the insert itself
  fails. Never logs or stores the token or any argument value.
- `App\Support\Mcp\McpContext` (MCP-1.2): readonly DTO — the resolved identity of an authenticated
  request (`organization`, `scopes`, optional `user`, originating `token`). `AuthenticateMcp` binds
  it into the container for the request; `McpContext::current()` returns it or `null` (local/stdio,
  in-process tests). The MCP analogue of the signed-in member `OrganizationScope` reads.
- `App\Support\Mcp\McpTenant` (MCP-1.2): `organization()` returns the authenticated context's org,
  or falls back to `McpDevOrg` when there is no context (local/stdio + tool tests). On HTTP a tool
  is never reached without a context (`AuthenticateMcp` 401s first), so the dev-org fallback only
  ever applies on the trusted local transport. The tools/resource call this instead of `McpDevOrg`.
- `config/mcp.php`: spike config (`spike.dev_org_id`, env `MCP_DEV_ORG_ID`), the MCP-1.1 HTTP block
  (`http.enabled` / `MCP_HTTP_ENABLED`, default `false`; `http.path` / `MCP_HTTP_PATH`, default
  `mcp`), and the MCP-1.4 `rate_limit` block (`rate_limit.per_minute` / `MCP_RATE_LIMIT_PER_MINUTE`,
  default `60`, `0` disables).

## Routes / Controllers

- `routes/ai.php` registers **two transports**:
  - **Local (stdio):** `Mcp::local('graphy', GraphyServer::class)` — an Artisan command
    (`php artisan mcp:start graphy`) the MCP Inspector / Claude Desktop launches. Kept for local
    dev and tests.
  - **Remote (Streamable HTTP, MCP-1.1):** `Mcp::web(config('mcp.http.path', 'mcp'), GraphyServer::class)`
    wrapped in a `Route::middleware(EnsureMcpHttpEnabled::class)->group(...)` so the gate covers all
    verbs (`GET`/`DELETE` → 405 Allow: POST per the Streamable HTTP spec; `POST` → the JSON-RPC
    handler). The package adds its own `ReorderJsonAccept`, `ValidateMcpHeaders` and
    `AddWwwAuthenticateHeader` middleware to the POST route; MCP-1.2 appends `AuthenticateMcp` to the
    POST route only (via `->middleware(AuthenticateMcp::class)` on the `Mcp::web(...)` return).
- `App\Http\Middleware\EnsureMcpHttpEnabled`: `abort_unless(config('mcp.http.enabled'), 404)`. The
  HTTP route is always registered (so it is testable) but 404s for everyone while the flag is off —
  the default, including production. This is the per-env master switch (and the MCP-7.2 kill-switch
  surface); it runs outermost, before auth.
- `App\Http\Middleware\AuthenticateMcp` (MCP-1.2): reads the `Authorization: Bearer` token, rejects
  a missing/unknown/revoked token with a bare HTTP **401** (the package's `AddWwwAuthenticateHeader`,
  which wraps it on the route, then attaches the `WWW-Authenticate: Bearer` challenge) *before* the
  JSON-RPC dispatcher runs, so no tool/resource ever executes unauthenticated. On success it stamps
  `last_used_at` (quietly) and binds the per-request `McpContext` (org + scopes + optional user). It
  is on the **POST route only**, so the GET/DELETE 405 stubs stay unauthenticated (they carry no
  data and keep the MCP-1.1 405 test valid).
- **Note (route cache):** `Mcp::web` adds real routes, so after pulling this change run
  `php artisan route:clear` (a stale `bootstrap/cache/routes-v7.php` will otherwise hide `/mcp`).

## Business Rules

- **Read-only.** The only tool is annotated `#[IsReadOnly]`; the resource is inherently read-only.
- **Org-scoped by the token (MCP-1.2).** On the HTTP transport the org comes from the authenticated
  token (`McpContext`), never from any model input, so cross-org access is impossible by
  construction (proven by the two-org isolation test). With no signed-in member the global
  `OrganizationScope` is a no-op, so the tool/resource still scope **explicitly** by `org_id`
  (`withoutOrgScope()->where('org_id', …)`) to the resolved org — belt-and-suspenders.
- **Auth is HTTP-only.** `AuthenticateMcp` runs on `POST /mcp`. The local/stdio transport and the
  in-process `GraphyServer::tool(...)` tests have no bearer token, so `McpTenant` falls back to
  `McpDevOrg` — dev-only, never reached over HTTP (the 401 fires first).
- **Capability scopes are enforced per tool (MCP-1.3).** A token carries a `scopes` set (default
  `read`). Each tool extends `McpTool` and declares `requiredScopes()`; the gate rejects a token
  missing any required scope with a clear MCP error *before* the tool's Action runs. The scope is
  necessary but not sufficient — the Action still enforces org tenancy, policies and the checklist.
  Resources are not yet scope-gated (they are read-only and every issued token holds `Read` by
  default); a resource base class can follow when a write/elevated resource appears.
- **Rate-limited per connection (MCP-1.4).** Every HTTP tool call is counted against a one-minute
  window keyed per token (falling back to org); over budget → a clean MCP error, never a 500. The
  trusted local/stdio transport is never throttled.
- **Every executed call is audited (MCP-1.5).** `McpTool` writes exactly one `mcp_call_logs` row per
  executed HTTP call (success *or* scope-denied error), via `RecordMcpCallAction`, recording only the
  argument *keys*. Audit failure never breaks the call. Rate-limited calls are not audited (already
  throttled).
- **No secret ever leaves the server (MCP-1.6).** Only safe fields are returned; the `mcp_call_logs`
  row stores no value/secret/token; `token_hash` is `#[Hidden]` and the plain token is never
  persisted or logged. The automated `McpSecretsGuardTest` enforces this across every registered
  tool/resource and fails if a new primitive is added without being covered. (Today only
  `id`/`name`/`status` for clients and the brand-brain markdown are returned — no tokens, keys or
  signed URLs.)

## How to run it (manual round-trip)

- MCP Inspector: `php artisan mcp:inspector graphy` — connect, list tools/resources, call
  `list-clients`, read the `graphy://brand-brain` resource.
- Claude Desktop: point a local (stdio) command connector at `php artisan mcp:start graphy`.

## Tests

- `tests/Feature/Mcp/GraphyMcpSpikeTest.php`:
  - the server advertises `ListClientsTool` and `BrandBrainResource` (`tools()/resources()
    ->assertRegistered`);
  - `list-clients` returns the dev org's clients and **does not** include another org's client
    (documents single-org reads — not a tenancy proof, which is MCP-1);
  - the brand-brain resource returns the `toGrokContext()` markdown;
  - `list-clients` reports an empty structured list when the dev org has no clients.
- `tests/Feature/Mcp/McpHttpTransportTest.php` (MCP-1.1):
  - every verb on `/mcp` 404s while `mcp.http.enabled` is false (the production default);
  - with the flag on, `GET /mcp` returns 405 + `Allow: POST` (proving the transport is mounted);
  - with the flag on, a `POST /mcp` `tools/list` JSON-RPC call (now with a bearer token, MCP-1.2)
    returns 200 and lists `list_clients` over HTTP (a real round-trip; a legacy request with no
    `_meta` reaches the dispatcher without an initialize handshake).
- `tests/Feature/Mcp/McpAuthTest.php` (MCP-1.2) — the auth + tenancy proof:
  - an unauthenticated `POST /mcp` is rejected 401 with a `WWW-Authenticate: Bearer` challenge;
  - an unknown bearer token and a revoked token are both rejected 401;
  - **two-org isolation:** org A's token sees only org A's client (never org B's) and vice versa;
  - an authenticated call stamps `last_used_at`;
  - `CreateMcpTokenAction` stores only the SHA-256, never the plain token.
- `tests/Feature/Mcp/McpScopeTest.php` (MCP-1.3) — the capability-gating proof (via the test-only
  `Tests\Fixtures\Mcp\ScopeTestServer` registering `list_clients` + `Tests\Fixtures\Mcp\SampleWriteTool`):
  - a `read`-only token calling the `write:drafts` tool is rejected with a scope error (and the tool's
    work never runs);
  - a token with `write:drafts` runs the write tool;
  - with no `McpContext` (trusted local/stdio) the gate is a no-op and the tool runs;
  - `list_clients` is rejected for a token lacking `read`, and runs for one holding it.
- `tests/Feature/Mcp/McpRateLimitTest.php` (MCP-1.4): over-budget HTTP calls return a clean
  `isError` "Rate limit reached" result (HTTP 200, not a 500); `per_minute = 0` disables it; the
  per-token key means one connection can't throttle another.
- `tests/Feature/Mcp/McpAuditTest.php` (MCP-1.5): one call writes exactly one `mcp_call_logs` row
  with the expected fields and the plain token absent from every column; a scope-denied call is
  recorded as `error`; a forced insert failure is swallowed (the call is unaffected) and logged at
  `error`.
- `tests/Feature/Mcp/McpSecretsGuardTest.php` (MCP-1.6): a coverage assertion forces the guard to be
  revisited when a primitive changes; no declared schema / advertised definition names a secret-shaped
  field; and the real output of each read primitive — invoked against seeded data with a connected
  account's sentinel access token — leaks neither the token nor any secret-shaped field.

## Package facts & API deltas (verify each phase)

- Package: **`laravel/mcp` v1.1.0** (requires PHP ^8.2, Illuminate ^11/^12/^13 — fine on Laravel 13).
  Installed on Windows with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  (Horizon declares those; they're satisfied in the committed lock).
- Routes published via `php artisan vendor:publish --tag=ai-routes` → `routes/ai.php`.
- Scaffolding: `make:mcp-server`, `make:mcp-tool`, `make:mcp-resource`, `make:mcp-prompt`.
- **Delta:** `Response::structured([...])` returns a `Laravel\Mcp\ResponseFactory`, not a `Response`,
  so a tool `handle` that uses it must widen its return type to `Response|ResponseFactory`.
- Annotations are PHP attributes: `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`,
  `#[IsOpenWorld]` (namespace `Laravel\Mcp\Server\Tools\Annotations\*`).
- Testing API: `GraphyServer::tool(Class, [args])`, `::resource(Class)`, `::tools()`, `::resources()`,
  `::actingAs($user)`; assertions `assertOk`, `assertSee`, `assertDontSee`, `assertStructuredContent`,
  `assertRegistered`, `assertHasErrors`, `assertName/Title/Description`.
- **Auth — decision recorded (MCP-1.2):** we use the **manually-issued bearer-token** flow (the
  plan's Appendix B "simpler" option and the package's documented Sanctum / custom-middleware path),
  via `App\Models\McpToken` + `App\Http\Middleware\AuthenticateMcp`, **not** Passport's full OAuth
  authorization server. Rationale: the package's Passport path only issues a single `mcp:use` scope,
  so Graphy's org + capability-scope resolution must live in-app regardless; Passport would add key
  management, migrations and DCR for no scope benefit here. The server still behaves as an OAuth 2.1
  **resource server** (bearer token + `WWW-Authenticate: Bearer` 401 challenge). **Deferred:** the
  OAuth authorization-server pieces — dynamic client registration (DCR) and a hosted authorize/token
  flow — which MCP-6 can add in front of the same token/org resolution if a client requires them.

## Known Gaps / Open Questions

- **Resources are not scope-gated yet.** Only tools (via `McpTool`) check scopes, are rate-limited and
  are audited; `BrandBrainResource` does none of these. It is read-only and every issued token holds
  `Read` by default, so this is safe for now — revisit with a resource base class if a resource ever
  needs an elevated scope (and extend rate limiting/audit to resources then).
- **Off by default is a deployment choice, not a missing guardrail.** Phase MCP-1 is complete, so the
  endpoint is safe to enable; it stays off (`MCP_HTTP_ENABLED`) until there is a settings surface to
  issue tokens (MCP-6.1) and a per-org master switch (MCP-7.2). No audit *UI* yet — `mcp_call_logs` is
  written but only surfaced in Settings in MCP-7.1.
- **Phase 13 convergence** (`.junie/plans/mcp-server.md` §Prerequisites): Phase 13 (Public API /
  `ApiToken` / Sanctum) is **not built** as of MCP-1.2, so this plan stood up its own token/org/scope
  layer (`McpToken`). **Convergence outcome:** MCP shipped first → if/when Phase 13 is built it must
  reuse `McpToken`'s org/scope resolution (or merge into it), not introduce a second token concept.
- **No OAuth authorization server (DCR / hosted authorize-token flow).** Tokens are issued manually
  (the MCP-6.1 settings page will expose that); DCR is deferred — see Package facts.
- Open: whether to expose the Inbox over MCP in v1; plan-gating/packaging (org vs. token). (Appendix B.)

## Changelog

- 2026-10-26: Phase MCP-0 (learning spike). Installed `laravel/mcp` v1.1.0; added `GraphyServer`
  (local/stdio), `ListClientsTool`, `BrandBrainResource`, `McpDevOrg`, `config/mcp.php`, `routes/ai.php`
  and `GraphyMcpSpikeTest`. No auth/tenancy/scopes yet — dev only.
- 2026-10-26: Step MCP-1.1 (remote Streamable HTTP transport). Added `Mcp::web('mcp', GraphyServer)`
  in `routes/ai.php` behind `EnsureMcpHttpEnabled` (new middleware), with a `config/mcp.php` `http`
  block (`enabled`/`path`, env `MCP_HTTP_ENABLED`/`MCP_HTTP_PATH`), defaulting **off**. Added
  `McpHttpTransportTest`. Still no auth/tenancy — the endpoint 404s unless explicitly enabled; OAuth
  arrives in MCP-1.2.
- 2026-10-26: Step MCP-1.2 (authentication + token/org/scope resolution). Added `McpToken`
  (`mcp_tokens` table + factory), `CreateMcpTokenAction`, `McpContext`, `McpTenant` and the
  `AuthenticateMcp` middleware (on `POST /mcp` only). Decision: manually-issued **bearer tokens**
  (resource-server model), not Passport/DCR (recorded under Package facts). `ListClientsTool`/
  `BrandBrainResource` now resolve the org via `McpTenant` (authenticated token's org on HTTP, dev
  org locally). Added `McpAuthTest` (401 rejection + two-org isolation + `last_used_at` + no-plain-
  token-stored); updated `McpHttpTransportTest` to authenticate. Phase 13 convergence: MCP shipped
  first, so Phase 13 must reuse this token layer. Scope gating, rate limits, audit and the secrets
  guard remain (MCP-1.3–1.6); endpoint stays off by default.
- 2026-10-26: Step MCP-1.3 (capability-scope gating). Added `App\Enums\McpScope`
  (`read`/`write:drafts`/`write:schedule`/`ai`/`inbox` + `default()`/`defaultValues()`/`label()`),
  the `App\Support\Mcp\Concerns\AuthorizesScope` gate and the `App\Mcp\Tools\McpTool` base class
  (`handle()` gates then delegates to `handleAuthorized()`). `ListClientsTool` now extends `McpTool`
  and requires `Read`; `CreateMcpTokenAction` defaults its scope set through `McpScope`. Added
  `McpScopeTest` with `ScopeTestServer`/`SampleWriteTool` fixtures (scope present/absent on a write
  tool and on `list_clients`; trusted-transport no-op). Pint + PHPStan L7 clean; 18 MCP tests green.
  Still remaining in MCP-1: rate limiting (1.4), audit logging (1.5), secrets guard (1.6).
- 2026-10-26: Steps MCP-1.4–1.6 — **Phase MCP-1 complete**. MCP-1.4: `RateLimitsCalls` concern +
  `config('mcp.rate_limit.per_minute')`; `McpTool` now rate-limits every HTTP call per token/org and
  refuses cleanly over budget (`McpRateLimitTest`). MCP-1.5: `McpCallLog` + `mcp_call_logs` migration +
  `RecordMcpCallAction` (DB::transaction, log-and-continue); `McpTool` writes one audit row per
  executed call with argument *keys* only (`McpAuditTest`). MCP-1.6: `McpSecretsGuardTest` enumerates
  every registered primitive and asserts no secret leaks (schema + real output, with an adversarial
  sentinel token) and forces coverage of new primitives. Pint + PHPStan L7 clean; 27 MCP tests green.
  Endpoint stays off by default as a deployment choice; next is Phase MCP-2 (read tools/resources).
