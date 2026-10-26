# MCP Server — Security Review (Phase MCP-7.3)

> Reviewed against the non‑negotiable security model in `.junie/plans/mcp-server.md` §Security.
> Date: 2026-10-26. Scope: the whole MCP surface as built in Phases MCP‑0 → MCP‑7. Verdict:
> **the security model holds; all isolation, secrets and scope tests are green in CI
> (`composer test` over `tests/Feature/Mcp`).**

Exposing an agency to an autonomous AI is powerful and dangerous. Each rule below is mapped to where
it is enforced and the automated test that proves it.

## 1. Everything is org‑scoped, always

- **Transport auth** resolves a bearer token to exactly one organization (`AuthenticateMcp` →
  `McpContext`); the org comes from the token, never from model input.
- **Query scoping** is belt‑and‑suspenders: the acting member is bound onto the auth guard so the
  global `OrganizationScope` applies to every reused Action/query, **and** each tool additionally
  pins the query with an explicit `->where('org_id', …)` (via `withoutOrgScope()`), so it is scoped
  even when no member resolves. Cross‑org row access is impossible by construction.
- **Route‑model binding** for the one place it exists (`McpToken` on the settings revoke route) is
  guarded, because `McpToken` has no org global scope (it is looked up by hash pre‑auth).
- **Evidence:** `McpAuthTest` (two‑org isolation — org A’s token sees only org A, and vice versa);
  `McpReadToolsTest::test_get_client_cannot_reach_another_orgs_client`,
  `::test_list_posts_is_scoped_to_the_org`; `McpConnectionSettingsTest::test_an_owner_cannot_revoke_another_orgs_token`.

## 2. MCP scopes gate capability

- A token carries a scope set; `Read` is the default (`McpScope::default()`), elevated scopes
  (`WriteDrafts`, `WriteSchedule`, `Ai`) are explicit opt‑ins. Every tool extends `McpTool`, declares
  `requiredScopes()`, and the gate rejects a missing scope **before any Action runs**.
- A scope is *necessary, not sufficient*: the reused Action still enforces tenancy, policies and the
  checklist on top.
- **Evidence:** `McpScopeTest` (read‑only token rejected from a write tool; present vs absent);
  `McpWriteToolsTest::test_a_read_only_token_is_rejected_from_creating_a_draft`;
  `McpScheduleToolsTest::test_write_schedule_scope_is_required`; `McpAiToolsTest::test_ai_scope_is_required`.

## 3. No tool bypasses a policy or a guardrail

- Tools call `App\Actions\*`; they never run raw writes. The acting member is bound, so policies
  (`PostPolicy::create/update/transition`, `ClientPolicy::viewBilling`, `createConnectInvite`) run as
  that member. `schedule_post` re‑runs the **full** `EvaluatePostChecklistAction` server‑side before
  enqueuing — MCP gets no shortcut over the web request’s gate.
- **Evidence:** `McpScheduleToolsTest::test_schedule_post_enqueues_the_publish_job_when_the_checklist_passes`
  (and the checklist genuinely blocked an un‑ready post during development);
  `McpWriteToolsTest::test_transition_rejects_an_illegal_move`.

## 4. Destructive / irreversible actions are confirmed and off by default

- Publishing/scheduling/connect tools require the elevated `WriteSchedule` scope and are annotated
  `#[IsDestructive]` so clients prompt for confirmation. `transition_post_status` is **capped at
  `approved`** over MCP — scheduling/publishing are unreachable through it.
- Two master switches sit in front: the per‑env `MCP_HTTP_ENABLED` (off by default; the route 404s)
  and the per‑org `organizations.mcp_enabled` (off by default; checked after auth → 403). A token
  revoke takes effect immediately (`findActive()` ignores revoked tokens).
- **Evidence:** `McpWriteToolsTest::test_transition_cannot_reach_scheduled_via_mcp`;
  `McpConnectionSettingsTest::test_the_org_master_switch_rejects_every_mcp_call_when_off`;
  `McpAuthTest::test_a_revoked_token_is_rejected`; `McpHttpTransportTest` (route 404s while disabled).

## 5. Treat all model/assistant input as untrusted (prompt‑injection aware)

- Every tool argument is validated like a Form Request (`$request->validate([...])`), reusing the
  same rules as the web requests (e.g. targets must reference the client’s own live accounts).
- Resources/tool outputs return **data, not instructions**; the server `#[Instructions]` explicitly
  tells the assistant to treat returned content as data. Prompts are orchestration text only — they
  drive the existing tools and never embed anything we then act on blindly.
- **No secret is ever returned.** Assets expose safe metadata only (never the signed `url`, storage
  `disk`/`path`, or thumbnail). Client/campaign billing fields are gated by `viewBilling`. The
  connect tool returns only a browser‑completable invite URL — never a vendor OAuth token.
- **Evidence:** `McpSecretsGuardTest` enumerates **every** registered tool/resource/prompt, fails if
  a new primitive is added without conscious coverage, asserts no schema/definition names a
  secret‑shaped field, and invokes each read primitive against seeded data containing a **sentinel
  access token**, asserting it never surfaces.

## 6. Everything is logged and metered

- Every executed HTTP tool call writes exactly one `mcp_call_logs` row (org, token id, tool, argument
  **keys only**, outcome, duration) via `RecordMcpCallAction`, which never throws (log‑and‑continue)
  and never records a value or secret. Calls log at `info`/`warning`; audit‑insert failure at `error`.
- Per‑connection rate limiting (one‑minute window, per token → per org) refuses a runaway loop with a
  clean MCP error, not a 500. AI tools respect the org AI‑enable flag and monthly token budget.
- **Evidence:** `McpAuditTest` (exactly one row; no secret persisted; failed/denied call recorded;
  insert failure swallowed); `McpRateLimitTest` (clean over‑budget error; per‑token key);
  `McpAiToolsTest::test_over_budget_returns_the_non_technical_limit_message`,
  `::test_ai_disabled_org_gets_a_clean_message`.

## Residual risks / follow‑ups

- **No OAuth authorization server (DCR / hosted authorize‑token flow).** Tokens are issued manually
  from Settings. Hosted clients that only speak OAuth connect via the `mcp-remote` header bridge for
  now. Adding DCR in front of the same token/org resolution is a clean future step.
- **Resources are not scope/rate/audit‑gated** the way tools are (only `BrandBrainResource` exists,
  read‑only, and every token holds `Read`). Introduce a resource base class before adding an elevated
  resource.
- **Inbox is intentionally not exposed over MCP in v1** (Appendix B open question). The
  `draft_inbox_replies` prompt is therefore deferred until inbox tools exist.
- **Prompt injection via returned content** is mitigated (data‑not‑instructions framing, no secrets,
  human‑in‑the‑loop on all writes) but, as everywhere, cannot be fully eliminated; the destructive‑
  action gating and the per‑org kill switch are the backstops.
