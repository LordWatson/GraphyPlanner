# Module Documentation

Each file in this folder documents one module of the app. Junie must update the relevant file every
time it adds/changes code belonging to that module (models, migrations, Actions, Services,
Contracts, controllers, routes, jobs, tests). This keeps documentation current so future requests have
accurate context without re-deriving it from the whole codebase.

## Modules (per `.junie/plans/graphy-development-steps.md`)

| File | Module | Status |
|---|---|---|
| `auth.md` | Authentication (Fortify), profile & security settings | Scaffolded |
| `organizations-users.md` | Organizations, users, roles & policies | Done (Step 0.2) |
| `clients.md` | Client (CRM) model + health computation | Done (Steps 0.3–0.4); health v2 (Step M-3.4) |
| `brand-brain.md` | BrandBrain model & editor | Done (Step 0.5) |
| `billing.md` | Client billing: invoices (line items, branded PDFs, email/reminders, void, ledger & CSV export) | Done (ad-hoc) |
| `plans.md` | Plans, entitlements & subscriptions: plan config, limit/gate enforcement, Cashier (Stripe) self-serve billing, super-admin plan controls + global/per-org Stripe switch, pricing pages | In progress (Phase CG-2) |
| `plans.md` | Plans, entitlements, AI allowance, Stripe subscriptions (Cashier), plan page & super-admin plan/billing controls | Done (Phase CG-2) |
| `social-accounts.md` | SocialAccount model & connection status | Done (Step 0.6); token lifecycle + client self-connect (Phase M-2) |
| `campaigns.md` | Campaigns: workspace, brief, plan, board, approvals, results (`.junie/plans/campaigns-enhancement.md`) | Done (Step 0.7); enhancement Done (Steps C-0.1 to C-9.4) |
| `assets.md` | Assets, asset library, S3 upload flow | Done (Step 0.7); private storage + signed URLs (Step I-1.6); probing + thumbnails (Step M-2.4) — S3 package pending |
| `posts.md` | Post, PostTarget, status transitions, checklist, editor UI, token review link (`/review/:token`) | Done (Steps 0.8–0.10, 0.13); per-channel content, formats, approval modes (Phase M-2) |
| `calendar.md` | Calendar UI | Done (Step 0.11) |
| `home.md` | Dashboard + Home / needs-attention | Done (Step 0.12) |
| `preview-mode.md` | Preview mode (`/preview/*`) | Done (Step 0.14) |
| `settings.md` | Org settings (API keys, default timezone) | Done (Step 0.15) |
| `publishing.md` | PublishAdapter interface & Upload-Post adapter | Done (Phase 1); pipeline hardened (Steps I-1.2–I-1.3); publishing parity (Phase M-2: registry, 9 networks, cancel/recall) |
| `ai.md` | AI (DeepSeek) trends & recommendations; AI Image Studio (Gemini) | Phase 2: Steps 2.1–2.9 done + the standout bits of 2.11–2.13 (MVP Phase M-7: control plane, async, writing tools, brand-fit, honest trends); Phase 16 Image Studio done |
| `client-portal.md` | Client portal: invitations, login & scoped views (Phase 6) | Done (Steps 6.1–6.6) |
| `notifications.md` | Notification bell (database notifications, mark-as-read + redirect) | Done (ad-hoc) |
| `staff.md` | Staff module: Owner-only roster/invite/edit/remove + activity logging | Done (ad-hoc) |
| `marketing.md` | Public marketing pages: welcome (`/`) and About (`/about`) | Done (ad-hoc) |
| `analytics.md` | Organization analytics & reporting: chapter 06, client drill-down, saved reports, CSV/PDF exports, scheduled emails, shared analytics core | Done (Phase 17, Steps 17.1–17.10); engagement, best time, client monthly reports (Phase M-3) |
| `ops.md` | Infrastructure: MySQL indexes, Redis, Horizon & named queues, PHP limits, benchmarks, observability, security headers, CI | Done (Steps I-2.1, I-2.8, M-1.1, M-1.2) |
| `super-admin.md` | Super admin console: org signup/onboarding, org CRUD + suspension, platform analytics, saved reports | Done (Phase 15) |
| `grid-planner.md` | Instagram grid planner: grid plans, layout core (PHP/TS), live profile feed, planner + preview, grid approval (review link + portal), tiles → posts, sequence scheduling, drift + order-at-risk, AI assist | Done (Phase 18, Steps 18.1–18.14) |
| `frontend.md` | Frontend foundation: shared `DataTable`, date/time + search pickers, `http` helper, CI guard rails, Vitest + Playwright harness | Done (Steps M-1.4, M-1.5; editor split M-1.6 in `posts.md`) |
| `inbox.md` | Social inbox: comment ingestion (`CommentSource`), threaded conversations, triage/assignment/saved replies/SLA, AI draft replies (human sends) | Done (Phase M-9) |
| `mcp.md` | MCP server ("Graphy for your AI assistant"): tools/resources/prompts exposing Graphy over the Model Context Protocol (`.junie/plans/mcp-server.md`) | In progress (Phase MCP-0 spike + MCP-1.1 HTTP transport + MCP-1.2 bearer auth/tenancy) |
| `business-workspaces.md` | Business & creator workspaces: `OrganizationType`, primary brand, `CurrentWorkspace`, workspace guards, role mapping, signup/onboarding, solo workflow, the business app shell (routes, nav, Today dashboard, posting goal, copy), managed publishing, tuned AI, and phone-first creation (PWA, quick post, push), and single-brand insights + media kit (`.junie/plans/business-workspaces.md`) | In progress (Phases B-0–B-8 done) |

Add new rows/files here as new modules are introduced. Only list files that exist:
`tests/Unit/ModuleDocsTest.php` fails CI when a row names a missing file or a module file has no row.

## File Template

When creating a new module file, follow this structure:

```markdown
# <Module Name>

## Purpose
Short description of what this module does and which plan step(s) it covers.

## Models / Tables
- `ModelName` (`table_name`): key columns, relationships.

## Actions / Services / Contracts
- `App\Actions\...`: what it does.
- `App\Services\...`: what it does.
- `App\Contracts\...` + implementations: what it abstracts and why.

## Routes / Controllers
- `METHOD /path` → `Controller@method` — brief description.

## Business Rules
- Key invariants, validation rules, state machines, edge cases.

## Tests
- PHPUnit test classes covering this module and what they assert.

## Known Gaps / Open Questions
- Anything incomplete, deferred, or needing a decision.

## Changelog
- YYYY-MM-DD: brief note of what changed and why.
```
