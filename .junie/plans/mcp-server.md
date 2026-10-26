# Graphy — MCP Server Plan ("Graphy for your AI assistant")

> **Status:** ✅ **Complete — Phases MCP-0 → MCP-7 all built and green.** The `/mcp` endpoint is
> authenticated, strictly org-scoped, capability-gated, rate-limited and audited, and now exposes the
> full tool/resource/prompt catalogue (read, draft/edit, elevated publish/schedule/connect, AI, and
> the `plan_week`/`fill_calendar_gaps` prompts), an Owner-only "AI connections" settings page with
> usage/audit, a per-org master switch, and a checked-in security review. It still ships **off by
> default** per environment (`MCP_HTTP_ENABLED`) and per organization (`organizations.mcp_enabled`).
> 58 MCP feature tests green; Pint + PHPStan L7 clean; tsc green. See `.junie/modules/mcp.md` for the
> as-built detail, `.junie/mcp-security-review.md` for the review, and
> `docs/connecting-your-ai-assistant.md` for the setup guide. Completed steps are marked **✅ DONE**.
> Deferred (documented): Inbox-over-MCP + the `draft_inbox_replies` prompt, OAuth DCR, and a dedicated
> marketing-site section / onboarding card (the settings page + docs are the self-serve surface).
> **Direction:** Graphy *is* an **MCP server** — we expose Graphy's data and actions so that AI
> assistants (Claude Desktop/web, ChatGPT, Cursor, and anything else that speaks MCP) can drive
> Graphy on the user's behalf. The headline: **"Run your entire social-media agency from inside your
> AI assistant."**
>
> This is a standalone plan (like `business-workspaces.md` and `campaigns-enhancement.md`), phased and
> step-by-step like `graphy-development-steps.md`. Read this whole top section before implementing any
> step — it explains what MCP is, why it fits Graphy, and the non-negotiable security model.

---

## 0. What is MCP, in plain English?

**MCP = Model Context Protocol.** It's an open standard (originally from Anthropic, now broadly
adopted) that lets AI assistants talk to external systems through one common "language." The usual
analogy is a **USB-C port for AI**: today every integration is custom per-assistant; with MCP you
build *one* server and *every* MCP-compatible assistant can plug into it.

Concretely, an **MCP server** (that's what we're building — Graphy) advertises three kinds of things to
an **MCP client** (Claude, ChatGPT, Cursor, etc.):

| MCP primitive | What it is | Graphy example |
|---|---|---|
| **Tools** | Actions the AI can *take* (like function calls). The assistant decides when to call them; the user approves. | `graphy.create_draft_post`, `graphy.schedule_post`, `graphy.discover_trends` |
| **Resources** | Read-only *data* the AI can load into its context (like files/URLs). | A client's brand brain, a post's full history, this week's calendar |
| **Prompts** | Pre-packaged *workflows* the user can trigger by name. | "Plan next week's content for {client}", "Draft replies to today's new comments" |

### How a request actually flows

```
  User (in Claude/ChatGPT)         MCP client            Graphy MCP server            Graphy app
  ------------------------         ----------            -----------------            ----------
  "Schedule the approved      ──▶  lists Graphy's   ──▶  advertises tools +      
   posts for Acme this week"       tools, asks the        resources (this org only)
                                   model which to call
                                                     ◀──
                             model picks            
                             graphy.schedule_post   ──▶  tool handler validates   ──▶  SchedulePostAction
                             (user approves)             + authorizes + scopes         (the SAME Action
                                                         to the token's org            the web UI calls)
                                                     ◀──  returns structured result ◀──
  "Done — 4 posts scheduled."◀──  model summarizes  
```

**The single most important idea for us:** an MCP tool is *just another thin entry point into the
exact same business logic the web UI already uses.* A controller validates input, calls an Action,
returns a response. An MCP tool handler does the same: validate input, call the **same Action**,
return a structured result. We do **not** reimplement any business rules — the checklist, the status
state machine, org-scoping, policies, DB transactions, and logging all come for free because the tool
calls `App\Actions\...`, never raw queries. (This is exactly CLAUDE.md principle #3.)

### Why "remote" and not "local"

MCP servers come in two transport flavours:

- **Local (stdio):** the server is a process on the user's own machine. Great for a dev spike and for
  the MCP Inspector, but useless for a hosted SaaS — our data lives on our servers, not theirs.
- **Remote (Streamable HTTP):** the server is an HTTP endpoint the assistant connects to over the
  internet, authenticated with **OAuth 2.1**. This is how Claude/ChatGPT "connectors" work, and it's
  what we ship to customers.

We use **local/stdio only as a learning spike in Phase MCP-0**, then build the real thing as a
**remote Streamable HTTP server** from Phase MCP-1 onward.

### How this maps onto Graphy's existing architecture

| MCP concept | Implemented in Graphy as |
|---|---|
| The server itself | A Laravel MCP server (see "Tech choice" below), mounted at e.g. `POST /mcp` |
| A tool | A small `App\Mcp\Tools\*` class: validates args, calls an existing `App\Actions\*`, maps the result to MCP output |
| A resource | A small `App\Mcp\Resources\*` class: reads via existing models/policies, returns read-only JSON/markdown |
| Auth / who-is-this | OAuth 2.1 (Phase MCP-1), resolving to an **org + a role-like MCP scope**, reusing the `org_id` tenancy already everywhere in the app |
| Tenancy | The resolved org scopes every query — identical to how controllers scope today |
| Permissions | A new `McpScope`/token-permission layer that mirrors `App\Enums\Role` + existing policies |
| Audit | Every tool call logged (Phase MCP-1) + surfaced in Settings (Phase MCP-7) |

### Tech choice (verify exact package/version at build time)

Laravel ships a first-party **`laravel/mcp`** package that provides server registration, tool/resource
classes, transport (stdio + Streamable HTTP), and an auth hook. **Preferred approach:** use
`laravel/mcp`. Confirm the current package name, version, and API when Phase MCP-0 starts (the exact
class signatures below are written against the documented shape and may need small adjustments — note
any deltas in `.junie/modules/mcp.md`, the same way other steps record "the exact shape wasn't
available in this repo"). If for any reason it's unsuitable, the fallback is a thin JSON-RPC 2.0
controller implementing the MCP spec directly — but that's a last resort; do not hand-roll the
protocol without reason.

### Non-negotiable security model (read before any write tool)

Exposing a whole agency to an autonomous AI is powerful **and dangerous**. These rules are mandatory
and are enforced incrementally across the phases below; no write/publish tool ships until its
guardrail exists:

1. **Everything is org-scoped, always.** A token resolves to exactly one org; every tool/resource
   query is scoped by that `org_id`. Cross-org access is impossible by construction — mirror the
   existing tenancy and cover it with a test, the way the review-portal isolation test works.
2. **MCP scopes gate capability.** A token carries a scope set (e.g. `read`, `write:drafts`,
   `write:schedule`, `ai`). Read-only is the default. Publishing/irreversible actions require an
   explicit elevated scope the Owner opts into.
3. **No tool bypasses a policy or a guardrail.** Tools call Actions; Actions already enforce the
   checklist (§6), the status state machine, and policies. A tool must never run a raw write.
4. **Destructive / irreversible actions are confirmed and/or disabled by default.** Publishing,
   connecting accounts, deleting, and sending anything to a client are gated behind elevated scopes,
   explicit confirmation semantics (MCP annotations: `destructiveHint`, `readOnlyHint`), and are
   off unless the Owner turns them on.
5. **Treat all model/assistant input as untrusted (prompt-injection aware).** A tool argument is user
   input, validated exactly like a Form Request. A resource we return to the model must never smuggle
   instructions we then act on. Never expose secrets (API keys, tokens, review tokens, raw webhook
   secrets) through any tool or resource — add an automated test asserting this, like Step 0.15 did.
6. **Everything is logged and metered.** Every tool call records who/which-token/which-org/which-tool
   (never the secret), success/failure, and counts toward rate limits and usage visibility.

---

## Prerequisites & relationship to Phase 13 (Public API)

The main development plan has a **Phase 13 — Public API & Outbound Webhooks** (org-scoped API tokens,
a minimal read API). **As of this writing Phase 13 is not built** (no `ApiToken` model, no Sanctum, no
`api.md`). This plan therefore stands up its own auth/tenancy foundation in Phase MCP-1 rather than
blocking on Phase 13.

**Convergence rule:** if Phase 13 is built first, MCP auth reuses its `ApiToken` model and org-scoping
instead of duplicating them (the OAuth layer sits *in front of* the same token/org resolution). If
this plan ships first, Phase 13's read API should be refactored to share the MCP tenancy/scope
resolution. Either way there is **one** token/org/scope concept, not two. Record which order happened
in `.junie/modules/mcp.md`.

Stack facts this plan assumes (verified against the repo): PHP 8.3, Laravel 13, Fortify auth, Horizon
queues, PHPUnit (class-based, `Tests\TestCase`), MySQL in prod / SQLite in tests.

---

## Phase MCP-0 — Foundations & "Hello, tool" (a learning spike)

**Goal:** understand MCP hands-on and stand up the thinnest possible working server with one harmless
read-only tool, driven from a real assistant. Optimise for *learning*, not production. Nothing here is
customer-facing; it may run only in local/dev.

### Step MCP-0.1 — Add the MCP package and a bare server — ✅ DONE
- Install the chosen MCP package (preferably `laravel/mcp`); verify name/version/API and record it.
- Register a single server (e.g. `App\Mcp\Servers\GraphyServer`) with name, version, and a short
  human description. Mount it on a **local stdio** transport only for now (an artisan command the MCP
  Inspector / Claude Desktop can launch).
- **Done when:** the MCP Inspector (or Claude Desktop pointed at the local command) connects to the
  server and lists it with zero tools, no errors.

### Step MCP-0.2 — One read-only tool: `list_clients` — ✅ DONE
- `App\Mcp\Tools\ListClientsTool`: no arguments (spike), returns a small structured list of clients
  (`id`, `name`, `status`) for a **hardcoded dev org** (auth comes in MCP-1 — do not ship this).
- Reuse the existing client query/transform; do not write new query logic.
- Mark the tool `readOnlyHint: true`.
- **Done when:** in Claude Desktop (or the Inspector), you can ask "list my clients" and the model
  calls the tool and reports the seeded dev org's clients. Screenshot/note the round-trip in
  `.junie/modules/mcp.md`.

### Step MCP-0.3 — One resource: a client's brand brain — ✅ DONE
- `App\Mcp\Resources\BrandBrainResource`: exposes a client's brand brain as a read-only resource
  (reuse `BrandBrain::toGrokContext()`'s markdown — it already exists and is the canonical context
  shape). Still dev-org-hardcoded.
- **Done when:** the assistant can load a brand brain as context and answer a question about the
  client's voice using it.

### Step MCP-0.4 — Write the explainer doc — ✅ DONE
- Create `.junie/modules/mcp.md` following the module template: purpose, the MCP primitives we expose,
  the server class, the tool/resource classes so far, and an **explicitly flagged** "Security: NOT
  YET SCOPED — dev only" note. Add its row to `.junie/modules/README.md`.
- **Done when:** `tests/Unit/ModuleDocsTest.php` passes with the new row/file.

### Phase MCP-0 exit criteria — ✅ DONE
- A real assistant lists clients and loads a brand brain through Graphy's MCP server, locally.
- The team understands tools vs resources vs prompts and the request flow first-hand.
- Everyone agrees this code is **not shippable** (no auth) — MCP-1 fixes that.

---

## Phase MCP-1 — Auth, tenancy, scopes & remote transport (the backbone)

**Goal:** make the server real and safe: reachable over the internet, authenticated, strictly
org-scoped, permission-gated, rate-limited, and audited. **No business tools are added in this phase**
— it only hardens the MCP-0 `list_clients`/brand-brain pair so they become genuinely multi-tenant.
This is the phase that makes everything after it safe.

### Step MCP-1.1 — Remote Streamable HTTP transport — ✅ DONE
- Expose the server over HTTP (e.g. `POST /mcp`) using the package's Streamable HTTP transport,
  behind the appropriate middleware group. Keep the stdio command for local dev/tests.
- **Done when:** the Inspector connects to the deployed HTTP endpoint (unauthenticated calls rejected
  — see MCP-1.2) and lists the server.

### Step MCP-1.2 — Authentication (OAuth 2.1) + token/org/scope resolution — ✅ DONE
- Implement MCP authorization per the spec: the server is an **OAuth 2.1 resource server**. Confirm
  the current spec flow (authorization-server metadata discovery, dynamic client registration if we
  support it, bearer access tokens) at build time and record the exact flow chosen.
- Back it with a token concept carrying `org_id` + a **scope set**. Reuse Phase 13's `ApiToken` if it
  exists; otherwise create `App\Models\McpToken` (or Sanctum PATs): `org_id`, `name`, hashed token,
  `scopes` (json), `last_used_at`, `revoked_at`. Raw token shown exactly once, never logged/persisted
  in plaintext.
- A resolved request yields an `App\Support\Mcp\McpContext` (readonly: `organization`, `scopes`,
  optionally a `user`/role). Every tool/resource receives it.
- **Done when:** an unauthenticated `/mcp` call is rejected; an authenticated one resolves to exactly
  one org; `list_clients` now returns **that token's org only**, proven by a two-org isolation test
  (mirrors the review-portal isolation test).

### Step MCP-1.3 — MCP scopes (capability gating) — ✅ DONE
- `App\Enums\McpScope` (e.g. `Read`, `WriteDrafts`, `WriteSchedule`, `Ai`, `Inbox`). Default for a new
  token: `Read` only. ✅ Added `McpScope` (string-backed; values are the stable wire format persisted
  in `mcp_tokens.scopes`), with `McpScope::default()`/`defaultValues()` as the single source of truth
  for the read-only default — `CreateMcpTokenAction` now defaults through it.
- `App\Support\Mcp\Concerns\AuthorizesScope` (or a base `McpTool`): a tool declares its required
  scope(s); the framework rejects the call with a clear MCP error if the token lacks it — before any
  Action runs. ✅ Did both: trait `App\Support\Mcp\Concerns\AuthorizesScope` (the gate) + base class
  `App\Mcp\Tools\McpTool` whose `handle()` runs the gate then delegates to `handleAuthorized()`, so a
  tool's work never runs for an under-scoped token. `ListClientsTool` now extends it and declares
  `Read`. No context (local/stdio + in-process tests) = trusted transport = gate is a no-op (mirrors
  `McpTenant`'s dev-org fallback; HTTP always has a context because `AuthenticateMcp` 401s first).
- Scopes compose with existing policies/roles: a scope is necessary but not sufficient; the underlying
  Action/policy still applies (e.g. a `WriteSchedule`-scoped token still can't act on another org).
  ✅ The gate only short-circuits a *missing* scope; the tool still calls its Action, which keeps
  tenancy/policy/checklist enforcement.
- **Done when:** a `Read`-only token calling a (future) write tool is rejected with a scope error, and
  a unit test covers scope-present / scope-absent for a sample tool. ✅ `tests/Feature/Mcp/McpScopeTest.php`
  proves it against a test-only `SampleWriteTool` (requires `write:drafts`) and `list_clients`
  (requires `read`): read-only token → write tool rejected with the scope error; correct scope → runs;
  no context → runs; `list_clients` rejected for a token lacking `read`. 18 MCP tests green.

### Step MCP-1.4 — Per-tool rate limiting & abuse guards — ✅ DONE
- Apply Laravel rate limiting keyed by token/org (and optionally per-tool cost), so a runaway
  assistant loop can't hammer the app or the vendor APIs. Sensible defaults in `config/mcp.php`.
  ✅ `App\Support\Mcp\Concerns\RateLimitsCalls` (used by `McpTool`) counts every HTTP call against a
  one-minute window keyed per token (falling back to org) via the `RateLimiter` facade. Budget is
  `config('mcp.rate_limit.per_minute')` (env `MCP_RATE_LIMIT_PER_MINUTE`, default 60; 0 disables).
  The trusted local/stdio transport is never throttled.
- **Done when:** exceeding the limit returns a clean MCP error (not a 500), covered by a test.
  ✅ `tests/Feature/Mcp/McpRateLimitTest.php`: over-budget calls return HTTP 200 with an `isError`
  "Rate limit reached" result (not a 500); `per_minute = 0` disables it; the key is per-token so one
  connection can't throttle another.

### Step MCP-1.5 — Audit logging — ✅ DONE
- `App\Actions\Mcp\RecordMcpCallAction` (wrapped in `DB::transaction()`, never blocks/fails the call —
  log-and-continue on its own error, like `RecordAiUsageAction`): records `org_id`, token id, tool
  name, arg *keys* (never secret values), outcome, duration. New `mcp_call_logs` table.
  ✅ Done exactly so — `RecordMcpCallAction` + `App\Models\McpCallLog` + the `mcp_call_logs` migration
  (`org_id`, nullable `mcp_token_id` [nullOnDelete, keeps history], `tool`, `argument_keys` json,
  `outcome`, `duration_ms`). `McpTool::handle` records one row per executed HTTP call (success *or*
  scope-denied error), passing only `array_keys($request->toArray())`.
- Log at `info` on success, `warning`/`error` on failure, with org/token/tool context — never the
  secret. (CLAUDE.md principle #6.) ✅ `info` on a successful call, `warning` on a failed one, and
  `error` if the audit insert itself throws (then continues). Context is org/token-id/tool/arg-keys —
  never the token or an argument value.
- **Done when:** a tool call writes exactly one audit row; a test asserts no secret/token value is
  ever persisted or logged. ✅ `tests/Feature/Mcp/McpAuditTest.php`: one call → exactly one row with
  the expected fields and the plain token absent from every column; a scope-denied call is recorded
  as `error`; a forced insert failure is swallowed (call unaffected) and logged at `error`.

### Step MCP-1.6 — Secrets-exposure guard (automated) — ✅ DONE
- A test that enumerates every registered tool/resource output schema and asserts no field can carry
  an API key, OAuth/access token, review token, or webhook secret (extend as tools are added). This is
  the MCP analogue of Step 0.15's props-payload test. ✅ `tests/Feature/Mcp/McpSecretsGuardTest.php`
  reflects the primitives registered on `GraphyServer` and (a) a coverage assertion fails when a
  primitive is added/removed so the guard is consciously extended, (b) asserts no declared schema
  field nor advertised definition contains a secret-shaped name, and (c) invokes each read primitive
  against seeded data that deliberately includes a connected account's (sentinel) access token and
  asserts the real output leaks neither the token nor any secret-shaped field.
- **Done when:** the guard test passes and is wired into CI (`composer test`). ✅ It lives under
  `tests/Feature/Mcp`, so `composer test` (PHPUnit over `tests/`) runs it in CI.

### Phase MCP-1 exit criteria — ✅ DONE
- `/mcp` is reachable, OAuth-authenticated, and strictly org-scoped (two-org isolation test green). ✅
- Scopes gate capability; rate limits and audit logging work; the secrets guard is green in CI. ✅
- `list_clients` + brand-brain resource are now genuinely multi-tenant and safe. Update `mcp.md`. ✅
  (27 MCP tests green; Pint + PHPStan L7 clean. The HTTP endpoint still ships **off by default** —
  `MCP_HTTP_ENABLED` — as a deployment choice, not a missing guardrail; MCP-7.2 adds the per-org
  master switch. Phase MCP-2 can now add read tools on this backbone.)

---

## Phase MCP-2 — Read tools & resources (safe, high-value, read-only)

**Goal:** the lowest-risk, highest-"wow" surface — let an assistant *understand* an agency. All
read-only, all `Read`-scope, all reusing existing queries/policies. This alone is already a demoable
selling point ("ask Claude anything about your clients and calendar").

### Step MCP-2.1 — Client & brand read tools/resources
- Tools: `list_clients` (filters: status/owner/tag), `get_client` (profile + computed health +
  `health_reason`, reusing `ClientHealthService`), `get_brand_brain`.
- Resources: per-client brand brain (from MCP-0.3, now scoped), client list.
- **Done when:** each returns the token-org's data only; health matches the UI; tests cover scoping.

### Step MCP-2.2 — Post read tools
- `list_posts` (filters: client, campaign, status, platform, date range), `get_post` (full post +
  per-target local times + status + checklist state, reusing `EvaluatePostChecklistAction` so the
  reported checklist never drifts from the editor).
- **Done when:** a post with two different-timezone targets reports both independent local times
  (reuses the London/Dubai invariant), proven by a test.

### Step MCP-2.3 — Calendar read tool/resource
- `get_calendar` (window + filters), returning occurrences with both `account_local` and
  `org_timezone` renderings — reuse `CalendarController`'s existing projection logic via a shared
  Action rather than duplicating it (extract one if needed).
- **Done when:** the same post's two targets render at correct local times through MCP, test-covered.

### Step MCP-2.4 — Campaigns & assets (read) + analytics (read)
- `list_campaigns`/`get_campaign`; `list_assets` (metadata only — never raw signed URLs to secrets);
  `get_analytics_summary` (reuse the analytics module's existing aggregations, read-only).
- **Done when:** all scoped + tested; assets expose only safe, policy-allowed fields.

### Phase MCP-2 exit criteria
- From Claude/ChatGPT, a user can explore clients, posts, the calendar, campaigns, and analytics for
  *their org only*, with answers matching the web UI. Demo script recorded in `mcp.md`.

---

## Phase MCP-3 — Write tools: drafts & edits (human-in-the-loop)

**Goal:** let the assistant *create and refine* content — but only drafts and safe edits, never
publishing. Requires `WriteDrafts` scope. Every tool calls an existing Action (so the checklist,
state machine, transactions, and logging all apply) and is annotated so clients prompt for user
approval.

### Step MCP-3.1 — Create draft post
- `create_draft_post` → existing `CreatePostAction`. Accepts client, caption, targets (per-account,
  with the independent local-time rules intact), optional campaign/assets. Validated like
  `StorePostRequest`.
- Annotate `destructiveHint: false` (it only creates a draft) but still return the created post's id +
  a link so the human can review in the UI.
- **Done when:** a draft lands via MCP, scoped + checklist-aware; two-timezone targets keep
  independent times; test-covered; a `Read`-only token is rejected.

### Step MCP-3.2 — Edit draft (caption, hashtags, media, targets, schedule times)
- `update_post` → existing `UpdatePostAction` (reuses `UpdatePostRequest` validation). Never
  auto-overwrites silently — the tool is explicit about what it changes and returns the diff.
- **Done when:** edits round-trip; invalid edits are rejected with clear MCP errors; tests cover
  happy-path + rejection.

### Step MCP-3.3 — Status transitions (up to approval only)
- `transition_post_status` → existing `TransitionPostStatusAction`, driven by the existing explicit
  `PostStatusTransitionMap`. **Via MCP, cap the reachable states at `approved`** — MCP must not reach
  `scheduled`/publishing in this phase (that's MCP-4, behind a stronger scope).
- **Done when:** illegal transitions and over-cap transitions are rejected by the same map the UI
  uses; tests cover valid + rejected.

### Phase MCP-3 exit criteria
- An assistant can take a brand brief → create a draft → refine caption/hashtags → move it to internal
  review/approved, all through MCP, with every guardrail the UI has. No publishing possible yet.

---

## Phase MCP-4 — Publishing, scheduling & account tools (elevated, careful)

**Goal:** the irreversible, outward-facing actions. These are the most dangerous tools in the system
and ship **off by default**, behind the `WriteSchedule` scope the Owner must explicitly grant, with
destructive annotations and confirmation semantics.

### Step MCP-4.1 — Schedule post
- `schedule_post` → the existing checklist-gated `scheduled` transition path (reuses
  `TransitionPostStatusAction` + `PublishPostJob`). The server re-runs the full checklist server-side
  before anything is enqueued — MCP gets no shortcut.
- Annotate `destructiveHint: true`; require `WriteSchedule` scope; record an elevated audit entry.
- **Done when:** scheduling via MCP enqueues a publish job at the correct UTC instant (reuses the §12
  scheduling acceptance test), and a token without `WriteSchedule` is rejected.

### Step MCP-4.2 — Account connect (guarded)
- `start_account_connection` → returns the existing Upload-Post/Meta connect URL for a human to
  complete in a browser. MCP **never** receives or stores OAuth tokens itself; it only initiates.
- **Done when:** the tool returns a connect URL scoped to the org's account; it cannot leak or accept
  any vendor token (secrets-guard test extended).

### Step MCP-4.3 — Cancel / recall (where the adapter supports it)
- `cancel_scheduled_post` → existing cancel/recall path in the publishing module. Destructive-hinted,
  `WriteSchedule`-scoped.
- **Done when:** cancel works through MCP where the adapter supports it, rejected cleanly where it
  doesn't; tests cover both.

### Phase MCP-4 exit criteria
- With `WriteSchedule` explicitly enabled, an assistant can schedule, cancel, and initiate account
  connections — each audited, confirmed, and impossible without the elevated scope. Default tokens
  cannot do any of this.

---

## Phase MCP-5 — AI, trends & prompt workflows (the "embedded strategist" surface)

**Goal:** expose Graphy's own AI (Phase 2 DeepSeek) and package multi-step agency workflows as MCP
**prompts**, so the assistant can run high-value playbooks in one shot. Requires the `Ai` scope and
respects the existing per-org AI enable flag + token budget.

### Step MCP-5.1 — AI tools
- `discover_trends` → `DiscoverTrendsAction`; `recommend_posts` → `RecommendPostsAction`;
  `suggest_captions` → `SuggestCaptionsForPostAction`. All reuse the existing Actions, guardrails
  (`weNeverSay[]`), caching, and `AiUsageGuard`/budget enforcement — surfaced as clean MCP errors
  (e.g. "Monthly AI limit reached").
- **Done when:** each returns structured suggestions; AI-disabled orgs / over-budget return the clear
  non-technical message; banned phrases never pass through (reuses the Step 2.6 regression style).

### Step MCP-5.2 — MCP prompts (packaged workflows)
- Register MCP **prompts** such as:
  - `plan_week` — "Plan next week's content for {client}": pulls brand brain + calendar gaps +
    trends, proposes draft posts.
  - `fill_calendar_gaps` — surfaces upcoming gaps (reuses Phase 2.12 gap detection) and drafts fillers.
  - `draft_inbox_replies` — (if/when the Inbox module is exposed) drafts replies a human sends.
- These are *orchestration only* — they call the same tools/Actions above; no new business logic.
- **Done when:** selecting a prompt in Claude runs the workflow end-to-end against the token's org,
  producing drafts (never auto-published), test-covered at the Action level.

### Phase MCP-5 exit criteria
- An assistant can run "plan next week for Acme" and get on-brand draft posts tied to real calendar
  gaps and trends, with budgets/guardrails enforced — the "strategist in your AI" moment.

---

## Phase MCP-6 — Distribution & go-to-market (turn it into the selling point)

**Goal:** make it *trivial* for a customer to connect Graphy to their assistant, and make the feature
visible and sellable. The tech exists after MCP-1–5; this phase is packaging, onboarding, and GTM.

### Step MCP-6.1 — "AI connections" settings page
- Owner-only settings tab to: create/revoke MCP tokens, choose each token's scopes (read-only by
  default; publishing/AI opt-in with clear warnings), see last-used + the audit trail (MCP-1.5), and
  copy the connection URL. Mirrors Step 0.15 / the Phase 13 "API access" tab (share it if Phase 13
  exists).
- **Done when:** an Owner can issue a scoped token and see its usage; non-Owners can't; tested.

### Step MCP-6.2 — One-click connector setup docs + manifest
- Produce copy-paste setup for each major client (Claude web/desktop "custom connector", ChatGPT
  connector, Cursor `mcp.json`), and any connector manifest/`.well-known` metadata the OAuth flow
  needs. Host a short docs page.
- **Done when:** a new user can connect Claude to Graphy in under ~2 minutes following the docs,
  verified by someone outside the build.

### Step MCP-6.3 — Guided in-app onboarding + marketing surface
- An onboarding card/checklist that walks the Owner through connecting their assistant; a marketing
  page section ("Graphy works inside your AI assistant") consistent with `.junie/brand-guidelines.md`.
- **Done when:** the onboarding flow is reachable and on-brand; the marketing section ships.

### Phase MCP-6 exit criteria
- A customer can self-serve: create a scoped token, connect Claude/ChatGPT/Cursor, and run a prompt —
  without talking to us. The feature is discoverable and marketed.

---

## Phase MCP-7 — Observability, limits, hardening & docs

**Goal:** production confidence — usage visibility, cost/limit controls, a security review, and
complete module docs.

### Step MCP-7.1 — Usage & audit UI
- Surface MCP usage (calls per tool/day, errors, top tokens) and the audit trail in Settings (reuse
  the analytics/AI-usage panel patterns). Optional: feed Pulse/Horizon/Sentry.
- **Done when:** an Owner can see what their connected assistants did, per tool and token.

### Step MCP-7.2 — Limits & kill switches
- Per-org and per-token rate/cost limits (config-driven); a per-org **master MCP enable switch** and a
  per-token revoke that takes effect immediately.
- **Done when:** flipping the org switch off makes `/mcp` reject every call for that org instantly,
  tested.

### Step MCP-7.3 — Security review & prompt-injection hardening
- Review against the §Security model: scope coverage on every tool, destructive annotations correct,
  resources carry no instructions we act on, secrets-guard covers all outputs, isolation tests across
  orgs, and rate limits sane. Consider running the repo's security-review skill.
- **Done when:** a written review is checked in; all isolation/secrets/scope tests green in CI.

### Step MCP-7.4 — Finalise module docs
- `.junie/modules/mcp.md` fully reflects the as-built server: every tool/resource/prompt, the auth +
  scope model, the Phase-13 convergence outcome, limits, audit, and Known Gaps. README row accurate.
- **Done when:** `ModuleDocsTest` green; docs match code.

### Phase MCP-7 exit criteria
- MCP is observable, limit-controlled, security-reviewed, and fully documented — production-ready.

---

## Appendix A — Suggested tool/resource catalogue (final target)

| Tool | Scope | Backing Action | Phase |
|---|---|---|---|
| `list_clients`, `get_client`, `get_brand_brain` | `Read` | existing client/brand queries + `ClientHealthService` | MCP-2 |
| `list_posts`, `get_post` | `Read` | existing post queries + `EvaluatePostChecklistAction` | MCP-2 |
| `get_calendar` | `Read` | shared calendar projection | MCP-2 |
| `list_campaigns`, `get_campaign`, `list_assets`, `get_analytics_summary` | `Read` | existing module queries | MCP-2 |
| `create_draft_post` | `WriteDrafts` | `CreatePostAction` | MCP-3 |
| `update_post` | `WriteDrafts` | `UpdatePostAction` | MCP-3 |
| `transition_post_status` (≤ approved) | `WriteDrafts` | `TransitionPostStatusAction` + `PostStatusTransitionMap` | MCP-3 |
| `schedule_post` | `WriteSchedule` | checklist-gated `scheduled` transition + `PublishPostJob` | MCP-4 |
| `start_account_connection` | `WriteSchedule` | existing connect flow | MCP-4 |
| `cancel_scheduled_post` | `WriteSchedule` | existing cancel/recall | MCP-4 |
| `discover_trends`, `recommend_posts`, `suggest_captions` | `Ai` | Phase 2 AI Actions | MCP-5 |

**Resources:** per-client brand brain, client list, post history, calendar window, analytics summary
(all `Read`, all org-scoped).

**Prompts:** `plan_week`, `fill_calendar_gaps`, `draft_inbox_replies` (orchestration over the above).

## Appendix B — Open questions to resolve during MCP-0/MCP-1

- Exact `laravel/mcp` package name/version/API (confirm vs. a JSON-RPC fallback).
- OAuth 2.1 details: do we support dynamic client registration, or issue tokens manually from the
  settings page first (simpler) and add DCR later?
- Phase 13 ordering: build the shared `ApiToken`/org-scope layer here, or wait for Phase 13?
- Do we expose the Inbox module over MCP in v1, or defer to a later step?
- Pricing/packaging: is MCP a plan-gated upsell, and does that gate live on the org or the token?

## Changelog
- 2026-10-26: Initial plan drafted (Graphy-as-MCP-server direction). Not yet implemented.
- 2026-10-26: Phases MCP-0 & MCP-1 implemented (backbone: transport, auth, scopes, rate limit, audit,
  secrets guard).
- 2026-10-26: Phases **MCP-2 → MCP-7 implemented and green.** Read tools + resources (MCP-2),
  draft/edit tools (MCP-3), elevated publish/schedule/connect tools (MCP-4), AI tools + prompts
  (MCP-5), the "AI connections" settings page + connector docs (MCP-6.1/6.2), and usage/audit UI +
  per-org master switch + token revoke + security review + finalised module docs (MCP-7). Acting-member
  binding lets the tools reuse the exact web-UI Actions/policies org-scoped. Deferred, with reasons
  recorded in `.junie/modules/mcp.md`: Inbox-over-MCP (and the `draft_inbox_replies` prompt), OAuth
  DCR, and a standalone marketing-site section / onboarding card (MCP-6.3 shipped as the settings page
  + `docs/connecting-your-ai-assistant.md`).
