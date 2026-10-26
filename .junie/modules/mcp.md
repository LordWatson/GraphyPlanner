# MCP Server

> ⚠️ **Security: NOT YET SCOPED — dev only.** Phase MCP-0 is a local/stdio learning spike with
> **no authentication and no real tenancy**. Every tool/resource reads from one hardcoded "dev org"
> (`McpDevOrg`). Nothing here is reachable over the internet and nothing here is shippable. Phase
> MCP-1 adds remote HTTP transport, OAuth 2.1, per-token org/scope resolution, rate limiting and
> audit logging before any customer sees it. See `.junie/plans/mcp-server.md`.

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
- `config/mcp.php`: spike config (`spike.dev_org_id`, env `MCP_DEV_ORG_ID`). Rate limits, scopes and
  auth config land here in MCP-1.

## Routes / Controllers

- `routes/ai.php` — `Mcp::local('graphy', GraphyServer::class)`. **Local (stdio) transport only**:
  an Artisan command (`php artisan mcp:start graphy`) the MCP Inspector / Claude Desktop launches.
  There is deliberately **no** `Mcp::web(...)` route yet (that's MCP-1, behind OAuth).

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

- **No auth, no tenancy, no scopes** — all MCP-1. `McpDevOrg` is temporary.
- No remote (HTTP) transport yet — MCP-1.1.
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
