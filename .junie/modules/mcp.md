# MCP Server

> ✅ **Complete: Phases MCP-0 → MCP-7.** Graphy is a production-ready MCP (Model Context Protocol)
> server. Over the remote Streamable HTTP transport (`POST /mcp`) it is a **bearer-token-authenticated,
> strictly org-scoped** resource server: unauthenticated/invalid requests are rejected 401 before any
> tool runs; a valid token resolves to **exactly one organization** (two-org isolation test green);
> tools are **capability-scope gated**, **rate-limited per connection** and **audited**, and an
> automated **secrets-exposure guard** covers every registered tool/resource/prompt. It exposes
> **read tools** (MCP-2), **draft/edit tools** (MCP-3), **elevated publish/schedule/connect tools**
> (MCP-4, destructive-hinted, `WriteSchedule`-gated), **AI tools** (MCP-5) and **packaged prompt
> workflows** (MCP-5.2), with an Owner-only **"AI connections" settings page** (MCP-6.1) to issue/
> revoke tokens and see usage/audit (MCP-7.1), a **per-org master switch** (MCP-7.2), and a checked-in
> **security review** (`.junie/mcp-security-review.md`, MCP-7.3). Two master switches sit in front:
> `MCP_HTTP_ENABLED` (per-env, off by default → route 404s) and `organizations.mcp_enabled` (per-org,
> off by default → 403 after auth). The local/stdio transport and in-process tool tests stay
> unauthenticated and resolve a single hardcoded "dev org" (`McpDevOrg`) for local dev only.
> See `.junie/plans/mcp-server.md` and `docs/connecting-your-ai-assistant.md`.

## Purpose

Graphy *is* an MCP server: it exposes Graphy's data and actions so AI assistants (Claude Desktop/web,
ChatGPT, Cursor, anything that speaks MCP) can drive the agency on the user's behalf — "run your
entire social-media agency from inside your AI assistant."

The guiding idea (CLAUDE.md principle #3): an MCP tool is *just another thin entry point into the
same business logic the web UI already uses.* A tool handler validates input, binds the token's
acting member onto the auth guard (so `OrganizationScope`, policies and the checklist all apply),
calls the same `App\Actions\*`, and maps the result to safe MCP output via `McpPresenters` — it never
reimplements business rules.

## MCP primitives we expose

All tools extend `App\Mcp\Tools\McpTool` (scope gate + rate limit + audit + `actAsOrganizationMember()`),
declare their required scope(s), and are org-scoped. Read tools are `#[IsReadOnly]`; the irreversible
MCP-4 tools are `#[IsDestructive]`.

| Primitive | Name | Scope | Backing Action/query | Phase |
|---|---|---|---|---|
| Tool | `list_clients` | `Read` | org-scoped `Client` query + `McpPresenters::clientRow` | MCP-2.1 |
| Tool | `get_client` | `Read` | `Client::healthFor()` + `ClientHealthService` | MCP-2.1 |
| Tool | `get_brand_brain` | `Read` | `BrandBrain::toGrokContext()` | MCP-2.1 |
| Tool | `list_posts` | `Read` | `ListPostsAction::query()` (pinned to org) | MCP-2.2 |
| Tool | `get_post` | `Read` | `EvaluatePostChecklistAction` + per-target local/org times | MCP-2.2 |
| Tool | `get_calendar` | `Read` | `GetCalendarPostsAction` + `BuildCalendarOccurrencesAction` | MCP-2.3 |
| Tool | `list_campaigns` | `Read` | org-scoped `Campaign` query | MCP-2.4 |
| Tool | `get_campaign` | `Read` | `GetCampaignOverviewAction` | MCP-2.4 |
| Tool | `list_assets` | `Read` | `SearchClientAssetsAction` (metadata only — no signed URLs) | MCP-2.4 |
| Tool | `get_analytics_summary` | `Read` | `GetOrganizationAnalyticsSummaryAction` (viewer role scoping) | MCP-2.4 |
| Tool | `create_draft_post` | `WriteDrafts` | `CreatePostAction` (+ `asset_ids` attached via `UpdatePostAction`) | MCP-3.1 |
| Tool | `update_post` | `WriteDrafts` | `UpdatePostAction` (returns the diff) | MCP-3.2 |
| Tool | `upload_media` | `WriteDrafts` | `CreateAssetFromBase64Action` → `SafeUpload` → `CreateAssetAction` | Media |
| Tool | `set_post_media` | `WriteDrafts` | `UpdatePostAction` (`asset_ids` sync; replace/add/remove) | Media |
| Tool | `request_media_upload` | `WriteDrafts` | `CreateMcpUploadSessionAction` (one browser link for a client + its posts; returns the URL, never the token) | Media (link) |
| Tool | `get_media_upload` | `WriteDrafts` | `McpUploadSession` status → per-post + library asset ids (`McpPresenters::asset`) | Media (link) |
| Tool | `transition_post_status` | `WriteDrafts` | `TransitionPostStatusAction` + `PostStatusTransitionMap` (capped ≤ approved) | MCP-3.3 |
| Tool | `schedule_post` | `WriteSchedule` | re-runs checklist, then `TransitionPostStatusAction` → `PublishPostJob` `#[IsDestructive]` | MCP-4.1 |
| Tool | `start_account_connection` | `WriteSchedule` | `CreateConnectInviteAction` (returns a connect URL, never a token) | MCP-4.2 |
| Tool | `cancel_scheduled_post` | `WriteSchedule` | `TransitionPostStatusAction` (scheduled→approved, recalls vendor) `#[IsDestructive]` | MCP-4.3 |
| Tool | `discover_trends` | `Ai` | `DiscoverTrendsAction` (budget + guardrails) | MCP-5.1 |
| Tool | `recommend_posts` | `Ai` | `RecommendPostsAction` | MCP-5.1 |
| Tool | `suggest_captions` | `Ai` | `SuggestCaptionsForPostAction` | MCP-5.1 |
| Resource | `brand_brain` | (read) | the org's first client's Brand Brain markdown (`graphy://brand-brain`) | MCP-0.3 |
| Prompt | `plan_week` | — | orchestrates get_brand_brain → get_calendar → trends/ideas → create_draft_post | MCP-5.2 |
| Prompt | `fill_calendar_gaps` | — | orchestrates get_calendar → recommend_posts → create_draft_post | MCP-5.2 |

`App\Support\Mcp\McpPresenters` is the single place that maps models to safe read shapes (gates
billing fields, never emits an asset's signed `url`/`disk`/`path`, drops owner-only health reasons).

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

- **`McpUploadSession` (`mcp_upload_sessions`)** — MCP media (link). A short-lived browser link that
  lets a human finish media uploads for the assistant (`request_media_upload`). One session is scoped
  to a client and a fixed set of its posts, and is **reusable** until it expires so it can take a whole
  batch. Columns: `org_id` (FK), `client_id` (FK), `token_hash` (unique SHA-256), `post_ids` (json —
  the allowed target posts; `[]` = library-only), `uploaded_asset_ids` (json — every asset created via
  the session, for the poll tool + library reporting), `expires_at`, `created_by` (nullable FK),
  timestamps. Uses `BelongsToOrganization`; `hashToken()` / `findActive()` (unscoped pre-auth lookup) /
  `isActive()` (`expires_at->isFuture()`) / `allowsPost()` mirror `ConnectInvite`. `const
  DEFAULT_TTL_MINUTES = 60`. Not single-use — a `config('mcp.upload.max_files')` cap bounds abuse.

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
- **Public media-upload page (MCP media).** `routes/web.php`, in the `throttle:public-token` group
  beside `connect/{token}` (unauthenticated — the token is the capability):
  - `GET mcp-upload/{token}` → `McpUploadController@show` — renders Inertia `mcp-upload/show` with the
    session's client + target posts; an unknown/expired session → the same page null props at **410**.
  - `POST mcp-upload/{token}` → `McpUploadController@store` — re-resolves the active session (410 if
    not), enforces the `max_files` cap, validates `file` with the **same rules as `StoreAssetRequest`**
    (`mimes:` + per-type size + `SafeUpload`) and `post_id` (must be one the session allows, else 403),
    creates the asset via `CreateAssetAction`, appends it to the post via `UpdatePostAction`, and
    records it on the session. JSON `{ asset }` on success; the session stays open for more files.
  - Named `mcp-upload.show` / `mcp-upload.store`. Frontend `resources/js/pages/mcp-upload/show.tsx`
    (`PublicShell`/`PublicTitle` + a local multi-file dropzone per post, posting via the `http`
    helper). After adding the routes run `php artisan route:clear` then `wayfinder:generate --with-form`.

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

## Media over MCP (upload an image from the assistant, use it on a post)

MCP tool calls carry only JSON arguments (`Laravel\Mcp\Request` exposes `arguments`/`meta`/`uri`, no
binary channel), so an image the user pastes/attaches in their AI assistant reaches Graphy as **base64
bytes** in a tool argument. There is deliberately **no server-side URL fetch** (no SSRF surface).

- **`upload_media`** (`WriteDrafts`) ingests the base64 `data` into a client's media library.
  `App\Actions\Assets\CreateAssetFromBase64Action` decodes it (tolerating a `data:…;base64,` prefix),
  writes it to a temp file, wraps it in a test-mode `Illuminate\Http\UploadedFile`, and validates it
  with the **exact same rules as `StoreAssetRequest` for `source=upload`** — the `mimes:` allowlist,
  the per-type size ceiling, and `App\Rules\SafeUpload` (content sniffing, SVG/HTML/script refusal,
  `VirusScanner`) — before delegating to the existing `CreateAssetAction` (private disk,
  `ProbeAssetJob`). So nothing the web uploader would reject can be stored via MCP. It returns only
  safe metadata via `McpPresenters::asset()` (**never** a signed URL/disk/path) plus `asset_id`, and
  enforces `AssetPolicy::create`. The raw bytes are never logged.
- **`set_post_media`** (`WriteDrafts`) sets a post's attached media from `asset_ids` with a `mode`
  (`replace` default / `add` / `remove`). Every id must belong to **the post's own client** in the org
  (cross-client/org ids are refused); it then delegates to `UpdatePostAction` so the carousel order
  (`post_assets.sort_order`), checklist refresh, revision and scheduled-post vendor recall/resubmit all
  apply. Never changes the post status.
- **`create_draft_post`** gained an optional `asset_ids` argument; when present it attaches the media
  through `UpdatePostAction` after `CreatePostAction` (one media/checklist path). So "create a post
  using this image" = `upload_media` → `create_draft_post(asset_ids)`; "replace the media on this post
  with this image" = `upload_media` → `set_post_media(mode=replace)`.

### Out-of-band upload link — a batch session (the reliable path for pasted/large images)

base64 only works for small images: a model can't reliably transcribe a real photo (~90K base64 chars)
into a tool argument without corrupting it, and it isn't a cap we can raise. **Verified:** MCP
`tools/call` carries only JSON (no client→server binary channel), Claude Desktop doesn't hand chat
attachments to tools, and the model only *sees* the image as pixels — so a pasted image can't be pulled
through MCP. (The MCP spec's own emerging direction, SEP-2631 `files/authorizeUpload`, is this same
one-time-upload-URL pattern.) The robust path hands the **human** the bytes via a browser link, reusing
the connect-invite pattern end to end, and is a **batch session** so one link covers many files and
many posts ("create 3 posts, I'll upload media for each"):

- **`request_media_upload`** (`WriteDrafts`) mints an `McpUploadSession` and returns a short-lived
  browser URL — **never the raw token** (only `upload_url`, like `start_account_connection`'s
  `connect_url`; the secrets guard covers it). Args: `client_id` (required), `post_ids` (optional — the
  posts the user will upload for, each must belong to the client), `expires_in_minutes` (5–240, default
  60). Authorizes `AssetPolicy::create` + `PostPolicy::update` for every post.
- The user opens the link (**no login** — the token is the capability, like `connect`/`review`). The
  page shows one upload area **per post** (plus a general "library" area when no posts were given), each
  accepting **multiple files**; `McpUploadController` ingests every file through the same `SafeUpload` +
  `CreateAssetAction` pipeline as the web uploader, **appends** it to the chosen post via
  `UpdatePostAction` (upload order = carousel order), and records it on the session. The session stays
  open (not single-use) until it expires; a `max_files` cap bounds abuse.
- **`get_media_upload`** (`WriteDrafts`, `#[IsReadOnly]`) polls by `session_id` → per-post attached asset
  ids + any library uploads (safe `McpPresenters::asset()` metadata), so the assistant sees what landed
  and chains the ids into `create_draft_post` / `set_post_media`.

Headline flow: `create_draft_post` ×N → `request_media_upload(post_ids:[…])` → user uploads per post →
`get_media_upload` → confirm. The model never carries bytes; no server-side URL fetch (no SSRF);
outbound responses never include a signed URL or storage path.

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
- `tests/Feature/Mcp/McpMediaTest.php` (Media): `upload_media` creates an asset from base64 without
  leaking a URL; it rejects a renamed non-image and an oversized file via `SafeUpload`/size (clean
  `isError`, not a 500) and requires `write:drafts`; `create_draft_post` attaches supplied `asset_ids`;
  `set_post_media` replaces/adds/removes, refuses another client's asset, and requires `write:drafts`.
- `tests/Feature/Mcp/McpUploadSessionTest.php` (Media link): `request_media_upload` mints one link for
  multiple `post_ids` without returning the token, refuses a post from another client and another org's
  client, and requires `write:drafts`; `get_media_upload` reports each post's assets + library uploads
  and is org-scoped.
- `tests/Feature/Mcp/McpUploadPageTest.php` (Media link): the public page renders the target posts and
  410s when expired/unknown; a file routes to the named post and **multiple files append in order**; a
  post outside the session is refused (403); a library upload (no post) lands in the library; a
  disallowed file is rejected by `SafeUpload`; the `max_files` cap is enforced. Uses `withoutVite()`
  (the local Vite manifest can be stale).
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

## MCP-2 → MCP-7 additions (as built)

**Acting member (MCP-2).** Read/write tools reuse Actions that scope via `Auth::user()`/
`OrganizationScope`. `AuthenticateMcp` binds the token's acting member (`McpContext::actingUser()` =
the token's user, else the org's Owner) onto the auth guard for the request, so every reused query/
policy runs org-scoped exactly as the web UI. `McpTool::actAsOrganizationMember()` does the same on
the trusted local transport (binds the dev org's Owner) and fails closed (null) when no member
resolves. Tools *also* pin queries with an explicit `org_id` filter (belt-and-suspenders).

**Models / columns.** `organizations.mcp_enabled` (MCP-7.2, boolean, default off; `OrganizationFactory`
defaults it on for tests, `mcpDisabled()` for the off path) — the per-org master switch checked in
`AuthenticateMcp` (403 when off, *after* the 401 auth check). No other new tables (MCP-2–5 are all
reuse; `mcp_tokens`/`mcp_call_logs` are unchanged from MCP-1).

**New Actions / support.**
- `App\Actions\Mcp\CreateMcpUploadSessionAction` (MCP media): mints an `McpUploadSession` and returns
  `['session', 'token', 'url']` where `url = route('mcp-upload.show', $token)`. `Str::random(64)` →
  stores only the SHA-256 → raw token returned once (in the URL), never logged. Single insert (no
  transaction, CLAUDE.md #5). Mirrors `CreateConnectInviteAction`.
- `App\Actions\Mcp\RevokeMcpTokenAction` (MCP-6.1/7.2): soft-revokes a token (`revoked_at`),
  idempotent, immediate effect (`findActive()` ignores it). Mirrors `RevokeConnectInviteAction`.
- `App\Actions\Mcp\GetMcpUsageAction` (MCP-7.1): read-only aggregation over `mcp_call_logs` for the
  settings page — totals, per-tool calls/errors and a recent audit trail (last 30 days), org-scoped,
  never exposing an argument value or token.
- `App\Support\Mcp\McpPresenters`: the safe read-shape mapper (see above).
- `App\Mcp\Prompts\PlanWeekPrompt` / `FillCalendarGapsPrompt`: orchestration-only prompts.

**Routes / controllers.**
- `App\Http\Controllers\Settings\McpConnectionController` (Owner-only via `OrganizationPolicy::update`)
  renders `settings/ai-connections` and handles `store` (create token via `CreateMcpTokenAction`,
  flashes the plain token once), `destroy` (revoke, org-guarded binding), `updateSettings` (toggle
  the per-org switch). Routes in `routes/settings.php` under `org.member`; the `edit` route is behind
  `RequirePassword` (it issues secrets). Named `mcp-connections.{edit,store,destroy,update}`.
- Frontend: `resources/js/pages/settings/ai-connections.tsx` (lists/creates/revokes tokens with scope
  checkboxes, shows the connection URL + one-time token, the master switch, and the usage/audit
  table). A new Owner-only "AI connections" tab in `resources/js/layouts/settings/layout.tsx`.
  Wayfinder TS regenerated with `php artisan wayfinder:generate --with-form`.

**Setup/GTM docs (MCP-6.2).** `docs/connecting-your-ai-assistant.md` — copy-paste connection config
for Claude Desktop, Cursor and hosted clients (via the `mcp-remote` header bridge), plus the local
stdio/Inspector path and the tool/scope catalogue. (OAuth DCR is deferred, so hosted "custom
connector" clients use the header bridge for now.)

**Security review (MCP-7.3).** `.junie/mcp-security-review.md` maps each §Security rule to where it
is enforced and the test that proves it.

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
- **Inbox over MCP is deferred** (Appendix B), so the `draft_inbox_replies` prompt from the plan is
  not registered — it would reference inbox tools that don't exist yet. `plan_week` and
  `fill_calendar_gaps` ship.
- **Marketing surface (MCP-6.3) is light:** the in-app connection surface is the settings page; a
  dedicated marketing-site section and a guided onboarding card are a documented follow-up (the
  settings page carries the connection URL + inline instructions, and `docs/connecting-your-ai-assistant.md`
  is the self-serve guide).

## Changelog

- 2026-10-26: Media over MCP — out-of-band upload link as a **batch session**. base64 can't carry real
  images reliably (verified: MCP tool calls are JSON-only, Claude Desktop doesn't forward attachments,
  the model can't reproduce bytes — and SEP-2631 shows the spec heading to this same upload-URL model).
  Added `request_media_upload` (mints an `McpUploadSession` for a client + a set of its posts →
  short-lived browser URL via `CreateMcpUploadSessionAction`, returns the URL not the token) +
  `get_media_upload` (poll → per-post + library asset ids), and the unauthenticated token-gated
  `mcp-upload/{token}` page (`McpUploadController`, in `throttle:public-token`) with a per-post
  multi-file dropzone that ingests each file through `SafeUpload` + `CreateAssetAction` and appends it to
  the post. One link handles many files across many posts ("create 3 posts, I'll upload media for each").
  New `mcp_upload_sessions` table/model/factory + `config('mcp.upload.{ttl_minutes,max_files}')`.
  Registered both tools on `GraphyServer`; `McpSecretsGuardTest` extended. Added `McpUploadSessionTest` +
  `McpUploadPageTest`. Model never carries bytes; no SSRF. **Adds a migration** → deploy needs
  `php artisan migrate` + `npm run build` + `route:clear` + `wayfinder:generate`. Pint + PHPStan L7 +
  tsc clean; new tests green.
- 2026-10-26: Media over MCP. Added `upload_media` (base64 ingest → asset, via new
  `App\Actions\Assets\CreateAssetFromBase64Action` reusing `SafeUpload` + `CreateAssetAction`) and
  `set_post_media` (`asset_ids` replace/add/remove via `UpdatePostAction`); `create_draft_post` gained
  an `asset_ids` argument. Both new tools require `WriteDrafts`, are client/org-scoped, and expose only
  safe asset metadata (no signed URL/path). Registered on `GraphyServer`; `McpSecretsGuardTest` list
  extended; added `McpMediaTest`. Base64-only by design (no server-side URL fetch → no SSRF). Pint +
  PHPStan L7 clean; `McpMediaTest` (8) + the MCP write/secrets-guard suites green. See
  `.junie/modules/assets.md` (ingest Action) and `docs/connecting-your-ai-assistant.md` (tool catalogue).
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
- 2026-10-26: **Phases MCP-2 → MCP-7 complete.** MCP-2: read tools (`get_client`, `get_brand_brain`,
  `list_posts`, `get_post`, `get_calendar`, `list_campaigns`, `get_campaign`, `list_assets`,
  `get_analytics_summary`) + `ListClientsTool` filters + `McpPresenters` + the acting-member binding
  (`McpContext::actingUser`/`McpTenant::actingUser`/`McpTool::actAsOrganizationMember`, `AuthenticateMcp`
  sets the auth user). MCP-3: `create_draft_post`/`update_post`/`transition_post_status` (≤approved).
  MCP-4: `schedule_post`/`start_account_connection`/`cancel_scheduled_post` (`WriteSchedule`,
  destructive). MCP-5: `discover_trends`/`recommend_posts`/`suggest_captions` + `plan_week`/
  `fill_calendar_gaps` prompts. MCP-6.1: `McpConnectionController` + `settings/ai-connections` page +
  `RevokeMcpTokenAction`. MCP-6.2: `docs/connecting-your-ai-assistant.md`. MCP-7.1: `GetMcpUsageAction`
  + usage/audit panel. MCP-7.2: `organizations.mcp_enabled` per-org master switch (403 after auth) +
  immediate token revoke. MCP-7.3: `.junie/mcp-security-review.md`. Secrets guard extended to cover
  every new primitive (incl. prompts); 58 MCP feature tests green; Pint + PHPStan L7 clean; tsc green.
