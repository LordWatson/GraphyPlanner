# MCP Server

> ⚠️ **Security: NOT YET SCOPED — dev only.** The server still has **no authentication and no real
> tenancy**: every tool/resource reads from one hardcoded "dev org" (`McpDevOrg`). As of Step
> **MCP-1.1** there is now a remote Streamable HTTP transport (`POST /mcp`), but it is **OFF by
> default** (`mcp.http.enabled` / `MCP_HTTP_ENABLED`) — while off (including production) every verb
> on the route 404s, so it is not internet-reachable. It stays off until Step **MCP-1.2** adds
> OAuth 2.1 + per-token org/scope resolution; the remaining MCP-1 steps add rate limiting, audit
> logging and the secrets-exposure guard before any customer sees it. See
> `.junie/plans/mcp-server.md`.

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
| Tool | `list_clients` | `App\Mcp\Tools\ListClientsTool` | Lists the dev org's clients (`id`, `name`, `status`) as structured content; no arguments. | `#[IsReadOnly]` |
| Resource | `brand_brain` | `App\Mcp\Resources\BrandBrainResource` | Returns the dev org's first client's Brand Brain as markdown, reusing `BrandBrain::toGrokContext()`. | `text/markdown`, URI `graphy://brand-brain` |
| Prompt | — | — | None yet (Phase MCP-5). | — |

## Models / Tables

- **None yet.** MCP-0 adds no tables. Phase MCP-1 introduces the token concept (`McpToken` or a
  reused Phase-13 `ApiToken`) and `mcp_call_logs` for audit.

## Actions / Services / Contracts

- `App\Mcp\Servers\GraphyServer` (extends `Laravel\Mcp\Server`): the server. Name `Graphy`, version
  `0.0.1`, with `#[Instructions]` describing the agency domain. Registers the tool and resource.
- `App\Support\Mcp\McpDevOrg`: resolves the single spike "dev org". Order: configured
  `mcp.spike.dev_org_id` → the seeded `graphy-agency` org → the lowest-id org → `null`. A deliberate
  stand-in for real tenancy; deleted/replaced by token→org resolution in MCP-1.
- `config/mcp.php`: spike config (`spike.dev_org_id`, env `MCP_DEV_ORG_ID`) and the MCP-1.1 HTTP
  block (`http.enabled` / `MCP_HTTP_ENABLED`, default `false`; `http.path` / `MCP_HTTP_PATH`,
  default `mcp`). Rate limits, scopes and auth config land here across the rest of MCP-1.

## Routes / Controllers

- `routes/ai.php` registers **two transports**:
  - **Local (stdio):** `Mcp::local('graphy', GraphyServer::class)` — an Artisan command
    (`php artisan mcp:start graphy`) the MCP Inspector / Claude Desktop launches. Kept for local
    dev and tests.
  - **Remote (Streamable HTTP, MCP-1.1):** `Mcp::web(config('mcp.http.path', 'mcp'), GraphyServer::class)`
    wrapped in a `Route::middleware(EnsureMcpHttpEnabled::class)->group(...)` so the gate covers all
    verbs (`GET`/`DELETE` → 405 Allow: POST per the Streamable HTTP spec; `POST` → the JSON-RPC
    handler). The package adds its own `ReorderJsonAccept`, `ValidateMcpHeaders` and
    `AddWwwAuthenticateHeader` middleware to the POST route.
- `App\Http\Middleware\EnsureMcpHttpEnabled`: `abort_unless(config('mcp.http.enabled'), 404)`. The
  HTTP route is always registered (so it is testable) but 404s for everyone while the flag is off —
  the default, including production. This is the MCP-1.1 guard **before** auth exists; MCP-1.2 adds
  the OAuth middleware that rejects unauthenticated callers, and this flag remains the per-env master
  switch (and the MCP-7.2 kill-switch surface).
- **Note (route cache):** `Mcp::web` adds real routes, so after pulling this change run
  `php artisan route:clear` (a stale `bootstrap/cache/routes-v7.php` will otherwise hide `/mcp`).

## Business Rules

- **Read-only.** The only tool is annotated `#[IsReadOnly]`; the resource is inherently read-only.
- **Single dev org.** With no signed-in member the global `OrganizationScope` is a no-op, so the
  tool/resource scope **explicitly** by `org_id` (`withoutOrgScope()->where('org_id', …)`) to the
  resolved dev org. This is NOT tenant isolation — it is a hardcoded single-org spike.
- **No secrets exposed.** Only `id`/`name`/`status` (clients) and the brand-brain markdown are
  returned; no tokens, keys or signed URLs. The automated secrets-exposure guard arrives in MCP-1.6.

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
  - with the flag on, a `POST /mcp` `tools/list` JSON-RPC call returns 200 and lists `list_clients`
    over HTTP (a real round-trip; a legacy request with no `_meta` reaches the dispatcher without an
    initialize handshake). Still single dev-org — auth/tenancy proof is MCP-1.2.

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
- **Auth (for MCP-1):** the package documents OAuth 2.1 via **Laravel Passport** (`Mcp::oauthRoutes()`
  + `auth:api` middleware, single `mcp:use` scope — custom OAuth scopes not supported, so Graphy's
  capability scopes must be enforced in-app), or Sanctum bearer tokens / custom middleware. Decide and
  record the exact flow in MCP-1.2.

## Known Gaps / Open Questions

- **No auth, no tenancy, no scopes** — all still open across the rest of MCP-1. `McpDevOrg` is
  temporary. The HTTP endpoint (MCP-1.1) is therefore flagged **off by default** until MCP-1.2 lands
  OAuth 2.1 + per-token org/scope resolution; do **not** enable `MCP_HTTP_ENABLED` anywhere internet-
  reachable before then.
- Remote HTTP transport exists (MCP-1.1) but is unauthenticated — MCP-1.2 adds OAuth 2.1 and the
  two-org isolation test.
- No rate limiting (MCP-1.4), audit logging (MCP-1.5), or secrets-exposure guard test (MCP-1.6).
- **Phase 13 convergence** (`.junie/plans/mcp-server.md` §Prerequisites): Phase 13 (Public API /
  `ApiToken` / Sanctum) is **not built** as of MCP-0. MCP-1 must either reuse its token/org layer if
  it lands first, or expose one the Phase-13 read API later shares. Record which order happened here.
- Open: OAuth dynamic client registration vs. manually issued tokens; whether to expose the Inbox over
  MCP in v1; plan-gating/packaging. (Appendix B.)

## Changelog

- 2026-10-26: Phase MCP-0 (learning spike). Installed `laravel/mcp` v1.1.0; added `GraphyServer`
  (local/stdio), `ListClientsTool`, `BrandBrainResource`, `McpDevOrg`, `config/mcp.php`, `routes/ai.php`
  and `GraphyMcpSpikeTest`. No auth/tenancy/scopes yet — dev only.
- 2026-10-26: Step MCP-1.1 (remote Streamable HTTP transport). Added `Mcp::web('mcp', GraphyServer)`
  in `routes/ai.php` behind `EnsureMcpHttpEnabled` (new middleware), with a `config/mcp.php` `http`
  block (`enabled`/`path`, env `MCP_HTTP_ENABLED`/`MCP_HTTP_PATH`), defaulting **off**. Added
  `McpHttpTransportTest`. Still no auth/tenancy — the endpoint 404s unless explicitly enabled; OAuth
  arrives in MCP-1.2.
