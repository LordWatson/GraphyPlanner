# Graphy — Business & Creator Workspaces (Full Scope)

Today Alima is built for **agencies**: an `Organization` is a studio, and everything it produces
(Brand Brain, social accounts, campaigns, assets, posts, AI ideas, daily briefs, invoices, analytics)
hangs off a `Client`. This plan adds a second kind of workspace for **one business or creator
managing their own social media**, with no agency in between.

- **Jess runs a bakery.** She wants to photograph this morning's sourdough, get a caption that
  sounds like her shop, and have it go out on Instagram and Facebook at 8am. She has no team, no
  clients, no invoices, and no time.
- **Jack is an influencer.** He posts every day across TikTok, Instagram and YouTube Shorts, works
  with brands on paid partnerships, and needs to see what's working and show it to sponsors. Later
  he may hire an editor.

This file sits alongside `.junie/plans/graphy-development-steps.md` (what to build) and
`.junie/plans/improvements.md` (what to fix/harden). How to use it (same rules as those plans):

- Each step is sized to hand to Junie as one unit of work: a goal, the files/models it touches, and
  a **Done when**.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), Actions/Services, interfaces in `App\Contracts\*` where swappable, `DB::transaction()`
  for multi-write logic, logging at boundaries (never secrets), PHPUnit coverage (happy path +
  rejections + regression tests), and the matching `.junie/modules/*.md` updated in the same change.
- Frontend steps follow `.junie/brand-guidelines.md` and use the `graphy-frontend-page` skill.
- Step ids are prefixed `B-` (e.g. `Step B-2.3`) so they never collide with `Step 2.3` or
  `Step I-2.3`. Mark finished steps `✅ Done` with an "Implemented via …" note.
- Priority tags: **[MVP]** needed for a business/creator to sign up, plan and publish on their own
  · **[V1]** needed to compete with Buffer/Later/Planoly for this segment · **[Stretch]**.

---

## 1. Product framing

### 1.1 Who it's for

| Segment | Example | What they need most | What they never need |
|---|---|---|---|
| Local business | Jess's bakery, a salon, a gym | Speed, a phone-first flow, ideas tied to seasons and local events, "just post it" | Clients, invoices, approval chains, retainers |
| Creator / influencer | Jack | Daily cadence over many platforms, analytics, sponsor deliverables, `#ad` compliance | Client portal, client health, billing to clients |
| Small in-house team | A 3-person marketing team at one company | Light review ("check before it goes out"), shared calendar, roles | Multi-client CRM |

### 1.2 Principles

1. **Same engine, different shell.** Publishing, the dual-timezone `PostTarget` model, the AI
   providers, Image Studio, the analytics core and the asset store are reused as they are. The
   business workspace is a new **shell** (routes, navigation, copy, defaults) and a few new
   **policies** (workflow, entitlements), not a fork of the domain.
2. **Zero agency vocabulary.** A business user never sees "client", "retainer", "portal",
   "waiting on client" or "invoice". Their brand is "your brand".
3. **Two taps to scheduled.** The default path is idea/photo → caption → schedule. Review is opt-in.
4. **No vendor keys.** A bakery owner will never paste an Upload-Post API key. Publishing and AI
   credentials are platform-managed for business workspaces.
5. **Grow without migrating.** A freelancer who starts as a business workspace can become an agency
   later with one action, keeping their history (Step B-12.1).

### 1.3 Non-goals (for this plan)

- A separate codebase, database or deployment for business users.
- Native iOS/Android apps (a PWA covers the phone use case first; see Phase B-7).
- Social inbox/engagement (owned by Phase 3 / Step I-15.1; business workspaces pick it up when it
  lands, with no extra work beyond the nav entry).
- Paid ads management.

---

## 2. Architecture decision: the "primary brand" model

### 2.1 The constraint

`client_id` is **non-nullable** on `posts`, `social_accounts`, `assets`, `campaigns`,
`daily_briefs`, `brand_brains` (1:1), AI trends/ideas and image generations, and it's the scoping key
for analytics, the calendar and policies. Making `client_id` nullable would touch nearly every
query, policy and test in the app and create two code paths for every feature.

### 2.2 Decision

- Add `organizations.type` (`App\Enums\OrganizationType`: `agency` | `business`), default `agency`
  so every existing org is unchanged.
- A business workspace owns exactly one **primary brand**: an ordinary `Client` row with
  `clients.is_primary = true`, created with the org at signup. Everything downstream (Brand Brain,
  accounts, posts, AI, analytics) works unchanged because it's still keyed by `client_id`.
- A MySQL unique index on a generated column (`primary_org_id = IF(is_primary, org_id, NULL)`)
  guarantees one primary brand per org at the database level (MySQL has no partial indexes; NULLs
  don't collide in a unique index).
- A request-scoped `App\Services\Workspace\CurrentWorkspace` resolves the org, its type and its
  primary brand once per request. Business controllers never take a `{client}` route parameter; they
  ask `CurrentWorkspace` for the brand and call the **same Actions** agency controllers call.
- Agency-only surfaces (clients CRM, client invitations, the client portal, invoices/billing, client
  health, review-by-client) are blocked for business orgs by middleware **and** by policies (defence
  in depth), so a hand-typed agency URL can't reach them.
- Behaviour that genuinely differs (post workflow, entitlements, navigation, copy) goes behind
  contracts resolved per org type, not `if ($org->type === ...)` branches scattered through Actions.

### 2.3 Why not the alternatives

| Option | Why not |
|---|---|
| Nullable `client_id` + org-level ownership | Doubles every query/policy path; very high regression risk across ~60 FKs and the analytics core. |
| A separate "Brand" model replacing `Client` | Massive rename across models, routes, frontend and docs for no product gain. |
| Separate app/database | Duplicated engine; publishing/AI fixes would land twice. |

The `Client` model keeps its name in code. Only the business UI calls it "your brand". Multi-brand
business workspaces (a bakery with two locations, a creator with a side brand) come almost for free
later: raise the brand limit and add a brand switcher (Step B-11.4).

### 2.4 What's reused, adapted or hidden

| Area (module doc) | Business workspace | Notes |
|---|---|---|
| Organizations & users (`organizations-users.md`) | **Adapted** | `type`, primary brand, role labels. |
| Clients CRM (`clients.md`) | **Hidden** | Primary brand row only; no list/create/delete UI. Client health replaced by "brand setup" score. |
| Brand Brain (`brand-brain.md`) | **Adapted** | Same model, simpler "Brand profile" editor + AI quick-start. |
| Social accounts (`social-accounts.md`) | **Reused** | Connect flow without `{client}` in the URL; managed credentials. |
| Campaigns & assets (`campaigns.md`, `assets.md`) | **Reused** | "Media library" and optional "Collections" (campaigns renamed). |
| Posts & editor (`posts.md`) | **Adapted** | New workflow policy; no client approval mode. |
| Calendar (`calendar.md`) | **Reused** | Single-brand, no client filter. |
| Home/dashboard (`home.md`) | **New page** | "This week" dashboard (Step B-4.3). |
| Review portal / client portal (`client-portal.md`) | **Hidden**, review link reused as "Share preview" (Step B-3.4). |
| Publishing (`publishing.md`) | **Reused** | + managed credentials (Phase B-5). |
| AI (`ai.md`) | **Reused + new prompts** | Local/seasonal ideas, caption from photo, weekly plan. |
| Analytics (`analytics.md`) | **Reused** core, new page | Single-brand analytics, creator media kit. |
| Billing — client invoices (`billing.md`) | **Hidden** | Replaced by the workspace's own subscription (Phase B-9). |
| Staff (`staff.md`) | **Adapted** | "Team", capped by plan. |
| Notifications (`notifications.md`) | **Adapted** | Copy and event set without client events. |
| Preview mode (`preview-mode.md`) | **Adapted** | A second fixture: "Preview Bakery". |
| Super admin (`super-admin.md`) | **Adapted** | Segment metrics by org type. |

---

## 3. Prerequisites from `improvements.md`

These must land first (or with Phase B-1), because business workspaces add many more self-serve,
untrusted tenants:

- **I-1.1** Preview mode must never create a real session (a second preview fixture is added in
  B-12.3; the existing session bug would apply to it too).
- **I-1.3** Publishing pipeline hardening. Solo users have nobody to notice a stuck `publishing`
  post.
- **I-1.6** Secrets, tokens and private media (business assets must not sit on permanent public
  URLs).
- **I-1.7** Rate limiting (self-serve signup + AI endpoints are an abuse surface).
- **I-1.9** Tenant-isolation safety net (more tenants, more risk).
- **I-3.1** Per-platform caption variants is strongly recommended before B-6 (creators post the same
  idea with different captions per platform).

---

## Phase B-0 — Decisions & groundwork

### Step B-0.1 — Record the architecture decision [MVP] ✅ Done
- Implemented via `.junie/modules/business-workspaces.md` + README row; naming ("one product, two
  signups") and free tier ("free tier + paid upgrade", payments deferred) resolved there.
- Add `.junie/modules/business-workspaces.md` (template from `.junie/modules/README.md`) with §2 of
  this plan as its Purpose/Business Rules, and a "Planned" row in the README module table.
- Resolve the open questions in §5 that block Phase B-1 (naming, free tier) and write the answers
  into the module doc.
- **Done when**: the module doc exists and the README table lists it.

### Step B-0.2 — Inventory of client-scoped surfaces [MVP] ✅ Done
- Implemented via the "B-0.2 — inventory" section of `.junie/modules/business-workspaces.md` (every
  `route:list` name classified agency-only / shared / business-only, driving B-1.4's guards).
- Produce a checklist (in the module doc) of every route, controller, policy, Inertia page,
  notification, mail and scheduled command that is agency-only, shared, or needs business copy.
  Seed it from `routes/web.php`, `app/Policies/*`, `app/Notifications/*`, `app/Mail/*` and
  `routes/console.php`.
- This list drives the guards in B-1.4 and the copy pass in B-4.4.
- **Done when**: every route name in `php artisan route:list` is classified as `agency-only`,
  `shared` or `business-only`.

---

## Phase B-1 — Workspace type & data model [MVP]

Goal: a business org can exist, has exactly one primary brand, and can't reach agency-only features.
No new UI yet.

### Step B-1.1 — `OrganizationType` and `organizations.type` [MVP] ✅ Done
- Implemented via `App\Enums\OrganizationType`, the `add_type_to_organizations_table` migration,
  `Organization` casts/helpers/`scopeOfType`, and `OrganizationFactory::business()`.
- `App\Enums\OrganizationType` (`Agency`, `Business`) with `label()`.
- Migration: `organizations.type` string, not null, default `agency`, indexed. Cast on
  `Organization`, plus `isBusiness()`/`isAgency()` helpers and `scopeOfType()`.
- `OrganizationFactory` gains a `business()` state.
- **Done when**: existing orgs migrate as `agency` and all existing tests pass unchanged; unit tests
  cover the helpers and scope.

### Step B-1.2 — Primary brand [MVP] ✅ Done
- Implemented via the `add_primary_brand_to_clients_table` migration (driver-aware generated
  `primary_org_id` + unique index), `Organization::primaryBrand()`,
  `App\Actions\Workspace\CreatePrimaryBrandAction`, and `ClientFactory::primary()`.
- Migration: `clients.is_primary` boolean default `false`, plus a stored generated column
  `primary_org_id` (`IF(is_primary, org_id, NULL)` on MySQL, `CASE WHEN is_primary THEN org_id END`
  on SQLite) with a unique index, so the same constraint holds on MySQL 8 and the SQLite test run.
- `Organization::primaryBrand()` (`HasOne<Client>` filtered on `is_primary`).
- `App\Actions\Workspace\CreatePrimaryBrandAction`: creates the `Client` (`status = active`,
  `is_primary = true`, name from the business name, `default_language`) and an empty `BrandBrain`,
  in one transaction. Logs `org_id`/`client_id`.
- `ClientFactory` gains a `primary()` state.
- **Done when**: tests prove a second primary brand for the same org is rejected by the database and
  by the Action, and that the Action creates the Brand Brain alongside.

### Step B-1.3 — `CurrentWorkspace` resolver [MVP] ✅ Done
- Implemented via `App\Services\Workspace\CurrentWorkspace` (bound `scoped`),
  `App\Exceptions\Workspace\WorkspaceMisconfiguredException`, and the `workspace` prop in
  `HandleInertiaRequests`.
- `App\Services\Workspace\CurrentWorkspace` (bound as `scoped` in `AppServiceProvider`):
  `organization()`, `type()`, `isBusiness()`, `brand()` (primary `Client`, cached for the request,
  throws a logged `WorkspaceMisconfiguredException` if a business org has no primary brand).
- Share `workspace: { type, brand: { id, name } | null }` from `HandleInertiaRequests` as a closure,
  so the frontend can switch shells and copy.
- **Done when**: unit tests cover agency (brand null), business (brand resolved), and a
  misconfigured business org (exception + log).

### Step B-1.4 — Guards: keep business orgs out of agency features [MVP] ✅ Done
- Implemented via `App\Http\Middleware\EnsureWorkspaceType` (alias `workspace`) applied to the
  agency-only route set in `routes/web.php`; `ClientPolicy::create`/`delete`, `InvoicePolicy` and
  `ClientInvitationPolicy` business denials; and the `ApprovalMode::ClientRequired` rejection in the
  post Form Requests. (The `clients/{client}/*` engine routes get their guard + redirect map in
  B-4; see the module doc's deferred list.)
- Middleware `workspace:agency` / `workspace:business` (`EnsureWorkspaceType`), registered as an
  alias in `bootstrap/app.php`. Apply `workspace:agency` to the agency-only routes from B-0.2
  (clients CRUD, invoices, invoice items, client invitations, the `/portal` group, client daily
  briefs, `clients/{client}/*` routes that have a business equivalent).
- Business orgs hitting an agency URL get redirected to the business equivalent when one exists
  (e.g. `/calendar` stays shared; `/clients/{id}/brand-brain` → `/brand`), otherwise 404 (not 403,
  so the feature isn't advertised).
- Policies, as the second layer: `ClientPolicy::create`/`delete` deny business orgs (and deny
  deleting any primary brand); `InvoicePolicy`/`ClientInvitationPolicy` deny business orgs;
  `ApprovalMode::ClientRequired` is rejected in `StorePostRequest`/`UpdatePostRequest` for business
  orgs.
- **Done when**: a data-provider feature test hits every agency-only route as a business Owner and
  asserts redirect/404, and policy unit tests cover each denial. Existing agency tests stay green.

### Step B-1.5 — Role mapping for business workspaces [MVP] ✅ Done
- Implemented via `Role::labelFor(OrganizationType)` + `Role::assignableFor(OrganizationType)`, the
  role restriction in `StoreStaffInvitationRequest` + `CreateStaffInvitationAction`, and the
  `roleLabels` map in the shared `workspace` prop.
- Keep the `Role` enum values (no migration of `users.role`). Add `Role::labelFor(OrganizationType)`
  so business UIs show: Owner → "Owner", Strategist → "Manager", Designer → "Creator", Viewer →
  "Viewer". `ClientReviewer` and `SuperAdmin` are never assignable in a business org
  (validated in the staff invitation request + Action).
- **Done when**: tests reject inviting a `client_reviewer` into a business org, and the labels
  render through the shared `workspace` prop.

### Phase B-1 exit criteria
- A business org with its primary brand can be created in a test via factories/Actions.
- Every agency-only route and policy rejects it; every existing agency test still passes.

---

## Phase B-2 — Signup & onboarding [MVP]

Goal: Jess goes from the marketing page to a configured brand with a connected account in under
five minutes.

### Step B-2.1 — Workspace-type-aware registration [MVP] ✅ Done
- Implemented via a type-aware `RegisterOrganizationAction` (creates the primary brand in the same
  transaction for `business`), `Organizations\BusinessRegistrationController` + `/start` route +
  `StoreBusinessRegistrationRequest` + `organizations/start` page, the `BrandKind` enum +
  `clients.brand_kind`, the business variant of `OrganizationWelcomeMail`, and the super-admin
  `type` selector.
- Refactor `RegisterOrganizationAction` to take a `type` (default `agency`, so callers are
  unchanged) and, for `business`, call `CreatePrimaryBrandAction` **inside the same transaction**.
  Welcome mail variant for business (`OrganizationWelcomeMail` gets a type-aware template, no agency
  language).
- New public route `GET/POST /start` → `Organizations\BusinessRegistrationController`
  (`create`/`store`), `StoreBusinessRegistrationRequest`: business/creator name, "I'm a…"
  (`business` | `creator` — stored on the primary brand's `industry`/new `brand_kind` column), your
  name, email, password, timezone (pre-filled from the browser), currency. Throttled like the agency
  signup. `/register-organization` gains a "Managing your own brand? Start here" link and vice versa.
- Super admin onboarding (`super-admin.organizations.create`) gets a type selector.
- **Done when**: feature tests cover both signups end-to-end (org type, primary brand, Brand Brain,
  owner role, welcome mail queued, verification event), validation failures, and the throttle.

### Step B-2.2 — Onboarding state [MVP] ✅ Done
- Implemented via `organizations.onboarding_completed_at` + `Organization::needsOnboarding()`, the
  `App\Services\Workspace\OnboardingProgress` service (checklist from real data), the `/welcome-setup`
  landing (business `LoginResponse` redirect) and skip/complete stamping.
- `organizations.onboarding_completed_at` (nullable) and an `App\Services\Workspace\OnboardingProgress`
  service computing the checklist from real data (not stored flags): brand profile basics filled,
  ≥1 connected account, ≥1 scheduled or published post, optional "invite a teammate".
- Business users with incomplete onboarding land on `/welcome-setup` after login; they can skip,
  which stamps `onboarding_completed_at`.
- **Done when**: unit tests cover each checklist item flipping from data, and a feature test covers
  redirect + skip.

### Step B-2.3 — Onboarding wizard UI [MVP] ✅ Done
- Implemented via `resources/js/pages/business/onboarding.tsx` (standalone, mobile-first, four
  skippable steps with a persisted data-driven stepper). **Your brand** and **Your voice** (AI
  quick-start) are wired to the B-2 Actions; **Connect accounts** and **Your first post** wire to the
  existing shared social-account connect and `clients.posts.store` → editor flows (brand implicit via
  `CurrentWorkspace`), pending the richer B-5.2 / B-6.2 versions.
- Inertia page `business/onboarding` with four short steps, each saving via existing/B-phase
  Actions:
  1. **Your brand**: name, what you do (one line), location (city/country — used for local ideas
     in B-6.1), website or main social handle.
  2. **Your voice**: AI quick-start (B-2.4) shows a draft voice/audience/pillars to accept or edit.
  3. **Connect accounts**: the connect flow from B-5.2, one card per platform.
  4. **Your first post**: three AI-suggested first posts (B-6.2); pick one → editor pre-filled.
- Mobile-first layout; every step skippable; progress persists across sessions.
- **Done when**: a browser walk-through on a phone viewport completes onboarding and produces a
  scheduled post; the `graphy-ui-reviewer` agent passes the page.

### Step B-2.4 — Brand profile AI quick-start [MVP] ✅ Done
- Implemented via `App\Actions\AI\DraftBrandProfileAction`, the `AiProvider::draftBrandProfile`
  contract method (DeepSeek + Null impls) with `BrandProfileInput`/`AiBrandProfileDraft` DTOs, the
  SSRF-guarded `App\Contracts\WebsiteSummarizer` → `HttpWebsiteSummarizer`, and the
  `business.onboarding.draft` endpoint. Nothing is persisted until acceptance (via
  `UpdateBrandBrainAction`); usage is logged to `ai_usage_logs`. (Allowance *enforcement* is deferred
  to B-9.2.)
- `App\Actions\AI\DraftBrandProfileAction`: input = name, one-liner, location, optional website URL /
  handle bio text; output = a draft for `BrandBrain` voice, audience, offer and content pillars via
  the existing `AiProvider` contract and guardrails (Step 2.6). Website text is fetched through a
  small `App\Contracts\WebsiteSummarizer` (HTTP fetch + readability strip; timeout, size cap, no
  following to private IP ranges — SSRF guard), faked in tests.
- Nothing is saved until the user accepts; acceptance goes through `UpdateBrandBrainAction`.
- Logs provider, latency and token usage into `ai_usage_logs`; counts against the AI allowance
  (B-9.2).
- **Done when**: tests cover the happy path with a fake provider, an SSRF-blocked URL, a provider
  failure (wizard falls back to an empty form with a friendly message), and that no `BrandBrain`
  write happens before acceptance.

### Phase B-2 exit criteria
- A new business user can sign up, get an AI-drafted brand profile, and reach the editor with a
  suggested first post, with no agency vocabulary anywhere in the flow.

---

## Phase B-3 — Workflow for one person (or a small team) [MVP]

Goal: a solo owner goes from draft to scheduled in one action; a small team can opt into review.

### Step B-3.1 — `PostWorkflow` contract [MVP]
- `App\Contracts\PostWorkflow`: `allowedTransitions(Post, User): list<PostStatus>`,
  `defaultApprovalMode(): ApprovalMode`, `requiresReview(Organization): bool`.
- `App\Services\Posts\AgencyPostWorkflow` wraps today's `PostStatusTransitionMap` unchanged.
- `App\Services\Posts\BusinessPostWorkflow`: removes `WaitingClient`/`ChangesRequested`
  (client-only), and — when review is **off** — allows `Draft → Approved` (self-approval, recorded in
  the activity log as "Marked ready"). When review is **on**, `Draft → InternalReview → Approved`
  as today, with `InternalReview → Draft` used for "changes needed".
- A resolver (`PostWorkflowResolver`, bound in a provider) picks the implementation from the post's
  org type. `TransitionPostStatusAction` and the editor's "allowed next steps" prop ask the resolver
  instead of reading the map directly.
- **Done when**: unit tests cover both workflows' full transition tables (data provider), and a
  regression test proves every agency transition is unchanged.

### Step B-3.2 — "Schedule now" compound action [MVP]
- `App\Actions\Posts\QuickSchedulePostAction`: from `Draft` (or `Idea`) with review off, validates
  the publish checklist, transitions `→ Approved → Scheduled` and dispatches publishing exactly as
  the normal path does, inside one transaction with one activity-log entry per transition and side
  effects after commit (per I-1.4). With review on it moves the post to `InternalReview` and
  notifies reviewers instead.
- Editor primary button for business: **Schedule** / **Post now** / **Send for review** depending on
  workflow state and whether a time is set.
- **Done when**: feature tests cover schedule, post-now, review-on routing, checklist failure
  (no state change), and an unauthorized Viewer.

### Step B-3.3 — Workspace review setting [V1]
- `organizations.require_post_review` (bool, default `false`), editable by the Owner in Settings
  (business variant). Review notifications go to Owners/Managers only.
- **Done when**: toggling the setting changes `BusinessPostWorkflow` output (tests) and existing
  in-flight posts in `InternalReview` remain valid after switching it off (they can still be approved).

### Step B-3.4 — "Share preview" link (reuse the review token) [V1]
- A business owner can share a read-only preview of a post (e.g. with a business partner or a
  sponsor) using the existing `ReviewToken` machinery, with decisions disabled by default and an
  optional "allow comments" flag. Copy on `/review/{token}` uses the brand name, never "client".
- For creators, this is how a sponsor approves a paid-partnership post (B-11.1): "allow approval"
  turns the decision buttons back on, and the decision is logged but doesn't change post status
  unless the creator opted into "require sponsor approval" on that post.
- **Done when**: tests cover view-only, comment-only and approval-enabled tokens, expiry, and that a
  business org never transitions to `WaitingClient`.

### Phase B-3 exit criteria
- A solo owner schedules a post from a draft with one click; a team with review on can't skip it;
  agency workflow behaviour is byte-for-byte unchanged (regression suite).

---

## Phase B-4 — The business app shell [MVP]

Goal: a business user gets their own navigation, URLs and dashboard, built from existing components.

### Step B-4.1 — Business routes and thin controllers [MVP] ✅ Done
- Implemented via the `business.*` route group in `routes/web.php` and thin controllers in
  `App\Http\Controllers\Business\*` (`DashboardController`, `PostController`, `MediaController`,
  `CollectionController`, `BrandController`, `AccountController`, `IdeaController`,
  `InsightController`) resolving the brand from `CurrentWorkspace` and calling the existing Actions;
  the shared Form Requests gained `Concerns\ResolvesWorkspaceBrand` (route `{client}` → implicit
  brand). The deferred agency `clients/{client}/*` engine routes are now `workspace:agency`-guarded,
  and `EnsureWorkspaceType::equivalents()` redirects a business org from each agency landing page to
  its shell equivalent.
- New route group (`auth`, `verified`, `org.member`, `workspace:business`) with brand-implicit URLs.
  Controllers in `App\Http\Controllers\Business\*` resolve the brand via `CurrentWorkspace` and call
  the **existing** Actions and Form Requests (extend the requests' `authorize()` to accept the
  implicit brand):

  | Route | Reuses |
  |---|---|
  | `GET /today` | new dashboard (B-4.3) |
  | `GET /calendar` | shared `CalendarController` (already org-scoped; hide client filter) |
  | `GET /posts`, `GET /posts/new`, `POST /posts` | `CreatePostAction`, editor page |
  | `GET /posts/{post}/edit` etc. | existing `PostController` routes (already brand-agnostic) |
  | `GET /ideas` | AI trends/ideas Actions for the primary brand |
  | `GET /media`, `POST /media` | `AssetController` Actions |
  | `GET /collections/*` | `CampaignController` Actions (campaigns renamed in copy) |
  | `GET/PUT /brand` (+ `/brand/references`) | `BrandBrainController` Actions |
  | `GET /accounts`, `POST /accounts/*` | `SocialAccountController` Actions + connect flow |
  | `GET /insights` | analytics core (Phase B-8) |
  | `GET /team` | Staff Actions (B-10) |
  | `GET /settings/workspace`, `/settings/plan` | B-3.3, B-9 |
- Business routes are registered with Wayfinder like the rest of the app.
- **Done when**: each route has a feature test as a business Owner (200 + correct brand scoping) and
  as an agency user (404/redirect), and controllers contain no business logic (convention reviewer
  passes).

### Step B-4.2 — Business navigation and layout [MVP] ✅ Done
- Implemented via `businessChapters` in `resources/js/lib/chapters.ts`, the `businessGroups()` branch
  in `app-sidebar.tsx` (and the business branch in the ⌘K `command-palette.tsx`), both driven by the
  shared `workspace.type` (read through the new `useWorkspace()` hook), and the `/today` login
  redirect in `LoginResponse`. The business shell pages live under `resources/js/pages/business/*`.
- Sidebar driven by the shared `workspace.type`: **Today, Calendar, Posts, Ideas, Media, Insights,
  Brand, Accounts**, then Team/Settings in the footer. No clients, invoices, portal or staff
  analytics chapters. ⌘K command palette gets the same entries.
- Login redirect: business orgs land on `/today` (Fortify home + `org.member` redirect logic).
- **Done when**: a frontend test (or browser check) verifies the business sidebar, and agency
  navigation is unchanged.

### Step B-4.3 — "Today" dashboard [MVP] ✅ Done
- Implemented via `App\Actions\Business\GetBusinessDashboardAction` (SQL-side, bounded per I-2.2) and
  the `resources/js/pages/business/today.tsx` page. Sections: **Up next**, **Needs you** (reusing
  `GetNeedsAttentionItemsAction` + stale drafts), **Gaps** (via `PostingGoalGapService`, B-4.5),
  **This week** (publishing consistency available today). **Idea of the day** is reserved for B-6.1
  (null for now). Metrics-dependent results are flagged `hasMetrics: false` until Phase B-8.
- `App\Actions\Business\GetBusinessDashboardAction` (SQL-side, per I-2.2), returning:
  - **Up next**: the next 5 scheduled targets with platform, local time and thumbnail;
  - **Gaps**: days in the next 14 with nothing scheduled (vs. the brand's posting goal, B-4.5);
  - **Needs you**: failed publishes, expired account connections, posts in review (if enabled),
    drafts older than 7 days;
  - **Idea of the day** (from B-6.1, cached daily);
  - **This week's results** (B-8.1 mini stats), when metrics exist.
- One empty state per section that links to the action that fills it (connect, create, generate).
- **Done when**: feature tests cover each section's query with fixtures (including timezone
  boundaries for "today" in the brand's timezone), and the page passes `graphy-ui-reviewer`.

### Step B-4.4 — Copy pass and terminology [MVP] ✅ Done
- Implemented via `resources/js/lib/workspace-copy.ts` and `App\Support\Workspace\WorkspaceCopy`
  (the PHP equivalent) mapping client→brand, campaign→collection, internal-review→review. Business
  shell pages use "brand"/"collection" throughout and never carry "retainer" or "invoice" (asserted
  in tests). Client-only notifications (`PostWaitingForClientReview`, client `PostApprovalDecided`,
  `InvoiceIssued`) are structurally never sent to a business org (its posts never reach
  `WaitingClient` and it has no invoices).
- A small `resources/js/lib/workspace-copy.ts` (and a PHP equivalent for mails/notifications) maps
  agency terms to business terms where shared components render them ("client" → "brand",
  "campaign" → "collection", "internal review" → "review").
- Notifications and mails from the B-0.2 inventory get business variants; client-only notifications
  (`PostWaitingForClientReview`, `PostApprovalDecided` from a client, `InvoiceIssued`) are never sent
  to business orgs.
- **Done when**: a test renders each shared mail/notification for a business org and asserts none
  contains "client"/"retainer"/"invoice"; a grep-based test over business pages' rendered props does
  the same for the obvious terms.

### Step B-4.5 — Posting goal [V1] ✅ Done
- Implemented via the `clients.posting_goal` JSON column + `App\Support\Workspace\PostingGoal` value
  object (`Client::postingGoal()`), the `App\Services\Business\PostingGoalGapService` (unit-tested
  across a week boundary and a DST change), and the posting-goal editor on the `/brand` page
  (`UpdateBrandProfileRequest`). Drives "Gaps" on Today; the calendar ghost slots and AI weekly plan
  (B-6.3) read the same value object.
- `clients.posting_goal` (json: posts per week per platform, optional preferred days) on the primary
  brand, set in onboarding or `/brand`. Drives "Gaps" on Today, calendar ghost slots, and AI weekly
  plans (B-6.3).
- **Done when**: gaps computation is unit-tested against goals across a week boundary and DST.

### Phase B-4 exit criteria
- A business user can do everything day-to-day (create, schedule, see calendar, manage media,
  brand and accounts) without ever seeing an agency page or URL containing a client id.

---

## Phase B-5 — Publishing without vendor keys [MVP]

Goal: Jess connects Instagram and Facebook with "Log in with…", with no API keys and no setup docs.

### Step B-5.1 — `PublishCredentials` contract and managed mode [MVP] ✅ Done
- Implemented via `App\Contracts\PublishCredentials` + `App\Services\Publishing\OrganizationOrPlatformCredentials`
  (bound in `AppServiceProvider`), the `App\Enums\PublishMode` enum, `organizations.publish_mode` +
  `organizations.upload_post_profile` columns, and `App\Actions\Workspace\EnsureManagedPublishProfileAction`.
  `UploadPostAdapter` (keys + publish profile), `UploadPostWebhookController` (webhook secret),
  `UploadPostMusicProvider` and `UploadPostEngagementSource` all resolve credentials through the
  contract — byo orgs use their own key, business orgs use `config('services.upload_post.platform_key')`
  and their own isolated profile. `RegisterUploadPostWebhookAction` stays byo-only (no managed key to save).
- Today `UploadPostAdapter`/`UploadPostMusicProvider` read `$account->organization->upload_post_key`.
  Introduce `App\Contracts\PublishCredentials` (`apiKeyFor(SocialAccount)`,
  `webhookSecretFor(Organization)`, `mode(Organization)`: `byo` | `managed`), with
  `App\Services\Publishing\OrganizationOrPlatformCredentials` as the implementation:
  - **byo**: the org's own key (today's agency behaviour, unchanged);
  - **managed**: a platform key from `config('services.upload_post.platform_key')` (env), with each
    org isolated as its own Upload-Post profile/user (`organizations.upload_post_profile`, created
    lazily by `EnsureManagedPublishProfileAction`).
- Business orgs are always `managed`; agencies stay `byo` unless a super admin flips them
  (future-proofing for agency plans).
- Webhook verification looks up the secret through the contract; managed-mode webhooks are routed
  to the right org by profile id.
- **Done when**: adapter tests (with `Http::fake()`) prove byo orgs still use their key, managed orgs
  use the platform key + their own profile, a managed org can't see another org's profile, and no
  key ever appears in logs.

### Step B-5.2 — One-tap account connect [MVP] ✅ Done
- Implemented via the platform-card `business/accounts` page (one card per enabled platform), the
  `POST /accounts/connect/{platform}` route (`business.accounts.connect-platform`) →
  `Business\AccountController::connectPlatform`, and `App\Actions\SocialAccounts\ConnectManagedAccountAction`
  (find-or-create the brand's account with defaults from the brand/org, then hand back the hosted
  connect URL via `ConnectSocialAccountAction`). The public `social-accounts.callback` maps the
  result back to the primary brand (already routed for business orgs in B-4). Advanced fields are
  edited through the existing `UpdateSocialAccountAction`.
- Business `/accounts` page: one card per platform from the rollout registry
  (`PlatformRolloutStage`), each opening the hosted connect flow for the org's managed profile; the
  callback (already public) maps the result back to the primary brand.
- Social account fields that agencies fill by hand (timezone, language, country) default from the
  brand/org and are editable under "Advanced".
- **Done when**: feature tests cover connect start → callback → account `connected` under the
  primary brand, a tampered callback (per I-1.6 signed state) being rejected, and reconnect of an
  expired account.

### Step B-5.3 — Plain-language publish failures [MVP] ✅ Done
- Implemented via `App\Enums\PublishFailureReason` (`classify()` maps raw vendor errors to a cause
  with `message()`/`fix()`/`isRetryable()`/`needsReconnect()`), surfaced per failed channel in the
  editor target props (`PostController`) and rendered in `RetryFailedTargetsRow` with a per-channel
  **Retry**. `RetryFailedPostTargetsAction` gained an optional `$only` target (the editor passes
  `target`); `PostController::retryFailedTargets` validates it belongs to the post. `PostPublishFailed`
  now sends email **and** the in-app bell for business orgs (agencies keep database-only), with the
  classified per-channel reasons.
- Map vendor/platform error codes to a `PublishFailureReason` enum with human copy and a fix action
  ("Instagram needs you to reconnect", "This video is longer than TikTok allows — trim to 10 min",
  "Facebook rejected the link preview — try again"). One-click **Retry** for the failed target only
  (depends on I-1.3).
- Email + in-app notification for failures in the brand's timezone; no failure is silent.
- **Done when**: unit tests map each known error code, and a feature test covers retrying one failed
  target without republishing the others.

### Phase B-5 exit criteria
- A business user can connect accounts and publish with no API key fields anywhere in their UI, and
  every failure tells them what to do next.

---

## Phase B-6 — AI tuned for small businesses and creators [V1]

All AI work goes through the existing `AiProvider`/`ImageProvider` contracts, guardrails (Step 2.6),
usage logging and the allowance from B-9.2.

### Step B-6.1 — Local & seasonal idea engine [V1] ✅ Done
- Implemented via `App\Contracts\EventCalendar` + `App\Services\Events\StaticEventCalendar` (curated
  GB/US + global dataset, Easter/nth-weekday resolvers, cached per year), `App\Services\AI\IdeaContextBuilder`
  (location/country/season + upcoming local dates + recent titles + pillars → `App\Support\AI\IdeaContext`),
  the `App\Contracts\LocalIdeaProvider` contract (DeepSeek + Null impls, bound like `GridAiProvider` so the
  agency `AiProvider` path is untouched), `App\Actions\AI\GenerateBrandIdeasAction` (context → provider →
  guardrail+persist via `RecommendPostsAction::filterAndPersist()` → `ai_usage_logs` via `AiTextUsage`),
  `App\Actions\AI\GenerateDailyIdeaAction` (idea-of-the-day cached per brand/local-day; read-only `cached()`),
  the `business:generate-daily-ideas` command (hourly, each brand at ~6am local, `withoutOverlapping()`/
  `onOneServer()`), the `/today` "Idea of the day" panel, and the `/ideas` "More ideas" button now routed
  through `GenerateBrandIdeasAction`. Config in `config/ai.php` `ideas` (`event_window_days`,
  `default_country`, `daily_hour`, `event_cache_ttl_hours`).
- `App\Services\AI\IdeaContextBuilder` adds to the prompt: brand location and timezone, upcoming
  public holidays and awareness days for the brand's country (`App\Contracts\EventCalendar`, a
  static dataset first, cached), season, recent posts (avoid repeats), and content pillars.
- `GenerateDailyIdeaAction` (scheduled per brand at 6am local) and an on-demand "More ideas" button
  on `/ideas`. Ideas reuse `AiPostIdea` so "Use this idea" → editor works as it does today.
- Example target output for Jess: "Pancake Day is Tuesday — post a 'behind the counter' reel of the
  crêpe batter at 7am."
- **Done when**: tests with a fake provider assert the context contains the brand's local events
  and excludes recent topics; the scheduled command is `withoutOverlapping()`/`onOneServer()`.

### Step B-6.2 — Caption from a photo [V1] ✅ Done
- Implemented via the `App\Contracts\VisionProvider` contract (`captionsFromImage`), its
  `App\Services\AI\GeminiVisionProvider` (Gemini `:generateContent`, image inline part + brand-grounded
  system instruction, strict-JSON `responseMimeType`, never throws → empty on failure) and
  `NullVisionProvider`, bound on `ai.enabled` + `ai.vision.provider === gemini` + key (open question
  §5.4 → Gemini). `App\Actions\AI\SuggestCaptionsFromMediaAction` loads the brand's image asset bytes
  (`AssetStorage`, size-capped, image-only), calls the provider with the brand's connected platforms,
  guardrails the result and records `ai_usage_logs` via the generalized `AiTextUsage`. Surfaced on the
  business `/media` library (a "Caption from photo" button on image tiles → a dialog of 3 copyable
  suggestions) through `POST /media/{asset}/captions` (`business.media.captions`, `throttle:ai`,
  Owner/Manager/Creator). Config in `config/ai.php` `vision`.
- Upload/take a photo → `SuggestCaptionsFromMediaAction` produces 3 captions in the brand voice plus
  hashtags per platform (respecting `hashtag_policy`), via a vision-capable provider behind the
  `AiProvider` contract (add `describeImage()` or a separate `VisionProvider` contract — decide in
  the step; fake in tests).
- **Done when**: tests cover the happy path, an unsupported media type, and a provider failure that
  leaves the user with an empty caption box (never a blocked editor).

### Step B-6.3 — "Plan my week" [V1] ✅ Done
- Implemented via `App\Actions\AI\GenerateWeeklyPlanAction` — fills the posting-goal gaps for the next
  7 days with plan items (`ai_post_ideas` rows marked `planned_for`, new nullable column), each with a
  best-time slot (`App\Services\Analytics\BestTimeService` data where there's enough of it, else
  `ai.plan.default_hour`) and local/seasonal content from the `LocalIdeaProvider`; one transaction,
  guardrailed + metered, idempotent (a day already covered by a scheduled post or an un-accepted plan
  item is skipped). `AcceptWeeklyPlanItemsAction` (→ draft `Idea` posts via
  `RecommendPostsAction::persist()`) and `DiscardWeeklyPlanItemsAction` do bulk accept/discard.
  Surfaced on `/ideas` ("Plan my week" button + a "This week's plan" panel with per-item and bulk
  Add/Discard) via `POST /plan/{generate,accept,discard}` (`business.plan.*`). Nothing is scheduled
  without confirmation (accepted items are `Idea` drafts). Config: `ai.plan.default_hour`.
- `GenerateWeeklyPlanAction`: fills the posting goal's gaps for the next 7 days with draft posts
  (status `Idea`, scheduled slots pre-set from best times, B-8.3) for the user to accept, edit or
  discard in bulk. Creates rows in one transaction; nothing is scheduled without confirmation.
- **Done when**: tests cover slot allocation against goals/timezones, idempotency (running twice
  doesn't duplicate), and bulk accept/discard.

### Step B-6.4 — Repurpose across platforms [V1] ✅ Done
- Implemented via the `App\Contracts\CaptionRepurposer` contract (DeepSeek + Null impls, bound like
  `LocalIdeaProvider`) and `App\Actions\AI\RepurposeCaptionsForPostAction`, which rewrites the post's
  master caption natively per target platform and returns one variant each — guardrailed (banned
  variants dropped, scrubbed) and clamped to each platform's caption-length/hashtag limits (I-3.3) in
  the Action, so a variant can never exceed what the platform accepts. Exposed as
  `POST posts/{post}/ai/repurpose` (`posts.ai.repurpose`, shared AiController, same gate as captions).
  In the editor, a "Repurpose per platform" button (compose section, shown with ≥2 platforms) fills
  each channel's per-platform override (I-3.1) in-memory via `editor.setChannel` — nothing saved until
  the user saves, matching the caption-suggest principle.
- From one post, generate platform-native variants (TikTok hook + short caption, Instagram caption
  with hashtags, LinkedIn/Facebook longer form), writing to the per-platform variants from I-3.1.
- **Done when**: tests assert variants respect each platform's limits from the capability registry
  (I-3.3).

### Step B-6.5 — Image Studio presets for small businesses [Stretch] ✅ Done
- Implemented by reusing the shared Phase 16 Image Studio engine with no new generation/quota code.
  `App\Enums\BusinessImagePreset` (`todays_special`, `opening_hours`, `sale_announcement`,
  `quote_card`) is the content source (label/tagline/field copy/default format + per-preset art
  direction); `App\Support\AI\Images\BusinessImagePresetComposer` turns a chosen preset + one line of
  text into a validated `GenerateImageSetData` (headline overlay always on and clamped to 80, subject
  embedding the brand name, variant count clamped to `ai.images.max_variants`). The brand's palette
  and typography are layered on by the existing `BrandImageBriefFactory`, never invented in the preset;
  the photographic preset keeps the brand's imagery style while the typographic cards override it so a
  text card is never rendered as a photo. A new business shell page `business/studio` (`GET /studio`
  `business.studio.index`) shows the preset cards + results, and `POST /studio/generate`
  (`business.studio.generate`, `throttle:ai-images`) composes and runs the set through
  `GenerateImageSetAction` (so the per-org rate limit and monthly quota are enforced exactly as the
  agency studio's are). Owners/Managers generate; a Creator sees results read-only and can download.
  The brand-agnostic `ai-image-*` routes (poll/use-in-post/refine/regenerate/finalise/discard/download)
  are shared, so the business studio reuses the agency `ImageTile`/`ImageLightbox`/polling components.
  Reachable from the Media page ("Create an image") and the sidebar's Media chapter (`/studio` prefix).
- One-tap presets on the existing Image Studio ("Today's special" card, "Opening hours", "Sale
  announcement", "Quote card") using brand colours/fonts from the Brand Brain visual references.
- **Done when**: each preset produces a valid `ImageGenerationRequest` (unit tests), quota enforced.

---

## Phase B-7 — Phone-first creation [V1]

Jess posts from behind the counter, Jack posts from wherever he is. The phone is the main device.

### Step B-7.1 — Installable PWA [V1] ✅ Done
- Implemented via `public/manifest.webmanifest` (name/scope/icons + `shortcuts` + the B-7.3
  `share_target`), the generated maskable/any icons under `public/icons/*`, the dependency-free
  `public/sw.js` (offline shell: cache-first for `/build`,`/icons`,`/brand`; network-first
  navigations → `public/offline.html`; no offline writes) and the business-only
  `resources/js/components/pwa/pwa-provider.tsx` — mounted in `app-sidebar-layout` only when
  `workspace.type === 'business'`, it injects the manifest link + `theme-color`, registers the
  service worker and shows a dismissible install card on `beforeinstallprompt`. Agency pages are
  untouched (no manifest link in the shared Blade root; asserted in tests). The CSP already allowed
  `worker-src`/`manifest-src 'self'`.
- Web app manifest (Alima icons per brand guidelines), service worker with an offline shell (no
  offline writes yet), install prompt on the business shell only.
- **Done when**: Lighthouse PWA installability passes on the business shell, and agency pages are
  unaffected.

### Step B-7.2 — Quick post flow [V1] ✅ Done
- Implemented via `Business\QuickPostController` (`business.posts.quick` / `.quick.store`), the
  `resources/js/pages/business/posts/quick.tsx` standalone mobile screen (capture → B-6.2 captions →
  account chips with localStorage-remembered defaults → "Next slot" / "Now" / pick a time →
  Schedule), `App\Actions\Posts\CreateQuickPostAction` (composes the shared `CreatePostAction` +
  attaches the one asset) handed to the existing `QuickSchedulePostAction`, and
  `App\Services\Business\NextSlotService` (the pure, timezone-aware "Next free slot" from the posting
  goal + default hour, skipping taken days). Media uploads reuse `business.media.store`; a checklist
  failure keeps the draft and opens the full editor. Entry points: the Today hero "Quick post"
  button and the PWA shortcut. (True byte-progress awaits I-7.1 direct-to-storage; the upload shows
  an indeterminate state via the shared `http` helper, consistent with the media library.)
- `/posts/quick`: a single mobile screen — camera/library picker → B-6.2 captions → pick accounts
  (defaults remembered) → "Next free slot" / "Now" / pick time → Schedule. Uses
  `CreatePostAction` + `QuickSchedulePostAction`; direct-to-storage uploads (I-7.1) with progress.
- **Done when**: a browser check on a phone viewport completes the flow in ≤5 taps after the photo,
  and a feature test covers the server side end-to-end.

### Step B-7.3 — Share-to-Alima [Stretch] ✅ Done
- Implemented via the manifest `share_target` (POST, `multipart/form-data`, `media` file) pointing at
  `business.posts.quick.share` → `QuickPostController::share`, which validates the shared media
  (same `SafeUpload` rules as the media library), ingests it with `CreateAssetAction`, and
  303-redirects into `/posts/quick?asset={id}&text=…` (title + text seed the caption). The quick
  page preloads that asset so the capture step is already done.
- Web Share Target in the manifest, so "Share → Alima" from the phone gallery opens the quick post
  flow with the media attached.
- **Done when**: share target POST handler is covered by a feature test (auth required, media
  validated, lands in quick post with the asset attached).

### Step B-7.4 — Push reminders [Stretch] ✅ Done
- Implemented via the `push_subscriptions` table + `PushSubscription` model (one row per
  user+browser, keyed on `endpoint_hash`), the `App\Contracts\PushNotifier` contract +
  `App\Contracts\PushDeliveryResult` enum + `App\Services\Push\WebPushNotifier` (bound default;
  configured = enabled + VAPID keys, so the opt-in → store → reminder pipeline runs end-to-end — the
  final encrypted VAPID delivery is the one piece deliberately left to a drop-in transport, logged
  until bound). Per-user opt-in through `business.push.{subscribe,unsubscribe}` (→ `Save`/`Delete
  PushSubscriptionAction`) surfaced by the `resources/js/hooks/use-push.ts` hook +
  `PushRemindersCard` on Profile settings (business-only). The "goes out in ~30 minutes" reminder is
  `App\Actions\Push\SendPostPushRemindersAction` + the `push:send-post-reminders` command (every 5
  min, idempotent via `post_targets.push_reminder_sent_at`, business orgs only, prunes `Gone`
  subscriptions); the SW's `push`/`notificationclick` handlers render and focus. Config in
  `config/push.php`. Publish **failures** already email + bell a business org (B-5.3); a push channel
  for failures and "nothing scheduled tomorrow" reuses the same contract and is a documented
  follow-up. I-12.1 preferences respected when they land.
- Web push (behind a `PushNotifier` contract) for "Post goes out in 30 minutes", failures, and
  "nothing scheduled tomorrow". Per-user opt-in; respects I-12.1 preferences when they land.
- **Done when**: tests cover subscription storage, opt-out, and the notifier being faked.

---

## Phase B-8 — Insights for one brand [V1]

Depends on engagement metrics (Phase 12 / Step I-10.1). Until those exist, insights show publishing
consistency only (posts published, on-time rate, streak), which is available today.

### Step B-8.1 — Single-brand insights page [V1] ✅ Done
- Implemented via `GetBusinessInsightsAction` now reading the shared `EngagementMetrics` scoped to
  the primary brand (reach/engagement by platform, top posts, follower growth) on top of the existing
  consistency slice, plus `BestTimeService` (B-8.3) for best times; the rebuilt
  `resources/js/pages/business/insights.tsx` renders consistency, reach/engagement, followers, top
  posts and best times with encouraging empty states until metrics land (`hasMetrics`). No revenue,
  client or staff-cost sections. Agency analytics is untouched. Covered by
  `tests/Feature/Business/BusinessInsightsTest.php`.
- `/insights` built on the shared analytics core (Phase 17), filtered to the primary brand. Sections:
  consistency (posts vs. goal, streak), reach/engagement by platform (when metrics exist), top posts,
  growth of followers per account.
- No revenue, client or staff-cost sections.
- **Done when**: feature tests cover the props for a business org with and without metrics; the
  agency analytics page is unchanged.

### Step B-8.2 — Plain-language takeaways [V1] ✅ Done
- Implemented via the `App\Contracts\InsightsSummarizer` contract (DeepSeek + Null impls, bound like
  `LocalIdeaProvider`) and `App\Actions\AI\SummarizeInsightsAction` (compact facts → provider →
  PII-scrubbed takeaways, cached once per brand per ISO week, metered in `ai_usage_logs`; empty when
  AI is off). Surfaced as a deferred `takeaways` panel on `/insights`, and as a weekly email digest:
  `WeeklyInsightsDigestMail` + `SendWeeklyInsightsDigestAction` + the `business:send-insights-digest`
  command (hourly, acts Monday morning per org timezone, idempotent per ISO week). Opt-out via
  `organizations.insights_digest_opt_out` (Workspace settings). Covered by
  `tests/Feature/Business/InsightsTakeawaysTest.php` and `WeeklyInsightsDigestTest.php`.
- `SummarizeInsightsAction` (AI, cached weekly per brand) turns the numbers into three sentences
  ("Your reels got 3× the reach of photos this month. Tuesday 8am is your best slot.") plus a weekly
  email digest (opt-out).
- **Done when**: tests with a fake provider cover the summary, the cache, and the digest mail
  content/opt-out.

### Step B-8.3 — Best times to post [V1] ✅ Done
- Implemented via `App\Services\Business\BestTimeService`, a business-shell layer over the shared,
  engagement-driven `App\Services\Analytics\BestTimeService`: per connected account it uses the
  account's learned slot when there's enough history, else a platform default
  (`analytics.best_time.defaults`), each in the account's timezone. Consumed by the Insights page
  (B-8.1) and available to the quick-post / weekly-plan slot pickers. Covered by
  `tests/Unit/Services/Business/BestTimeServiceTest.php` (fallback, data-driven path, timezone).
- `App\Services\Business\BestTimeService`: per account, from the brand's own published-post metrics
  when there are enough (threshold configurable), otherwise sensible platform defaults for the
  brand's industry and timezone. Used by the editor ("Suggested: Tue 08:00"), quick post and B-6.3.
- **Done when**: unit tests cover the fallback, the data-driven path, and timezone handling.

### Step B-8.4 — Creator media kit [V1] ✅ Done
- Implemented via the `media_kits` table + `App\Models\MediaKit` (one per primary brand), the
  `BuildMediaKitAction` (public-safe data only — published posts, public follower counts, never
  emails/drafts/internal notes; "past partners" empty until B-11.2), `UpdateMediaKitAction` /
  `ShareMediaKitAction` / `RevokeMediaKitAction` (opt-in, revocable public slug), and
  `GenerateMediaKitPdfAction` + `resources/views/pdf/media-kit.blade.php` (branded PDF via dompdf).
  Surfaced by `Business\MediaKitController` (`/media-kit` owner editor + share toggle + PDF) and the
  public `MediaKitShareController` (`/kit/{token}` + `/kit/{token}/pdf`), with a shared
  `resources/js/components/media-kit/media-kit-view.tsx` behind both the owner preview and the public
  `share/media-kit` page. Covered by `tests/Feature/Business/MediaKitTest.php`.
- One-page media kit (web + branded PDF via the existing dompdf pipeline): bio, audience by
  platform, follower counts, average engagement, top posts, past partners (B-11.2). Public share
  link with a revocable token, opt-in.
- **Done when**: tests cover generation, the token's revocation, and that private data (emails,
  unpublished posts) never appears.

---

## Phase B-9 — Plans, limits & self-serve billing [MVP for limits, V1 for payments]

Business workspaces are self-serve, so cost controls (AI spend, managed publishing volume) must be
enforced from day one, even before payments are switched on. Coordinate with I-11.3 so agencies and
businesses share one subscription system.

### Step B-9.1 — `Entitlements` contract [MVP]
- `App\Contracts\Entitlements` with `limit(Organization, Feature): ?int` and
  `allows(Organization, Feature): bool`; `Feature` enum: `social_accounts`, `scheduled_posts_per_month`,
  `team_seats`, `ai_text_credits`, `ai_image_credits`, `brands`, `analytics_history_days`,
  `media_storage_mb`.
- First implementation `ConfigEntitlements` reads plan definitions from `config/plans.php`
  (`business_free`, `business_pro`, `creator_pro`, `agency_*` with today's effective limits = no
  limit) and `organizations.plan` (string, default per type).
- Enforced in the **Actions** (`CreateSocialAccountAction`, `CreatePostAction`/scheduling, staff
  invitation, AI Actions, asset upload) with a typed `PlanLimitReachedException` the controllers
  render as a friendly upgrade prompt.
- **Done when**: one test per feature limit (at-limit allowed, over-limit rejected, logged), and
  agency orgs are never limited by default.

### Step B-9.2 — AI allowance metering [MVP]
- Monthly AI credit counters derived from `ai_usage_logs` (no separate counter table unless profiling
  says otherwise), checked before each AI call; the existing `ai_monthly_image_quota` folds into
  `ai_image_credits`.
- Usage meter visible on `/settings/plan` and next to AI buttons when below 20%.
- **Done when**: tests cover the month boundary in the org's timezone, the pre-call check, and the
  quota migration for existing orgs.

### Step B-9.3 — Subscriptions with Cashier (Stripe) [V1]
- Laravel Cashier on `Organization` (billable), Stripe Checkout for upgrade, Customer Portal for
  card/invoices/cancel, 14-day trial on paid plans, webhooks → `SyncSubscriptionAction` (idempotent)
  setting `organizations.plan`. Downgrades take effect at period end; over-limit data is kept but
  read-only (e.g. extra accounts paused, never deleted).
- Behind a Pennant flag (`billing.enabled`) so it can ship dark.
- **Done when**: webhook tests (signed payload fixtures) cover subscribe, upgrade, downgrade,
  cancel, payment failure (grace period then free plan), and replay idempotency.

### Step B-9.4 — Super admin plan controls [V1]
- Super admin can view/override an org's plan and grant temporary credits, with an audit entry.
- **Done when**: tests cover override + audit and that non-super-admins can't.

---

## Phase B-10 — Small teams [V1]

### Step B-10.1 — Team page for business workspaces [V1]
- Reuse the Staff module (invitations, activity log) under `/team` with business role labels
  (B-1.5), seat limits (B-9.1), and no client-reviewer option.
- **Done when**: invitation tests cover seat limits and role restrictions for business orgs.

### Step B-10.2 — Light review & mentions [V1]
- With `require_post_review` on, reviewers get a "To review" list on Today; comments and
  `@mentions` (already built) work unchanged.
- **Done when**: feature test covers a Creator submitting and a Manager approving, with notifications.

### Step B-10.3 — Freelance helper access [Stretch]
- Jack hires an editor for a month: time-boxed seat (auto-expires), restricted to Creator role.
- **Done when**: tests cover expiry (scheduled command revokes access) and the audit trail.

---

## Phase B-11 — Creator & business extras [V1/Stretch]

### Step B-11.1 — Paid partnership compliance [V1]
- Post fields: `is_paid_partnership`, `partner_name`, `partner_handle`. The publish checklist
  requires a disclosure (`#ad`/`#sponsored` or the platform's branded-content flag where the
  adapter supports it) when set, per platform. Optional sponsor approval via B-3.4.
- **Done when**: checklist tests block scheduling without disclosure and pass with it, per platform.

### Step B-11.2 — Brand deals tracker [Stretch]
- `brand_deals` (org-scoped, primary brand): partner, deliverables (n posts per platform), due
  dates, fee, status; posts link to a deal; deliverable progress fills automatically as linked posts
  publish. Feeds the media kit's "past partners" (opt-in per deal).
- **Done when**: tests cover deliverable progress from published posts and org scoping.

### Step B-11.3 — Link-in-bio page [Stretch]
- A public, brand-styled page (`/@{handle}`) with links and the latest published posts, with click
  tracking via I-10.4 link tracking.
- **Done when**: page renders only published content, handles are unique, and clicks are counted
  (tests).

### Step B-11.4 — Multiple brands for one business [Stretch]
- Lift the `brands` entitlement above 1 for higher plans: a brand switcher in the business shell,
  `CurrentWorkspace::brand()` reads the selected brand from the session (validated against the org),
  and `is_primary` remains the default. No agency features are unlocked by this.
- **Done when**: tests cover switching, that the session can't select another org's brand, and the
  limit.

### Step B-11.5 — Google Business Profile posting [Stretch]
- Local businesses get more from Google posts than LinkedIn. Add as a platform through the adapter
  pattern (Phase 4 / I-3.4) when the vendor supports it.
- **Done when**: covered by the adapter's contract tests.

---

## Phase B-12 — Growth paths, marketing & platform [V1]

### Step B-12.1 — Upgrade a business workspace to an agency [V1]
- `ConvertBusinessToAgencyAction` (Owner only, confirm dialog): flips `type` to `agency`, keeps the
  primary brand as a normal client (`is_primary` cleared), switches publishing credentials to
  `byo` only if the user provides a key (otherwise the org keeps `managed` — the contract allows
  it), moves the plan to the nearest agency plan, logs and audits. One transaction; reversible only
  by a super admin.
- Agency → business is **not** offered (an agency with many clients has no single brand).
- **Done when**: tests cover data preservation (posts, accounts, analytics still resolve), the
  navigation switch, and the rejection for non-Owners.

### Step B-12.2 — Marketing pages and pricing [V1]
- Welcome page section and a `/for-business` + `/for-creators` page (brand guidelines), pricing
  table generated from `config/plans.php` so prices never drift from enforcement.
- **Done when**: pages pass `graphy-ui-reviewer`, and a test asserts the pricing table reads the
  plan config.

### Step B-12.3 — Business preview fixture [V1]
- A second frozen preview org ("Preview Bakery", `type = business`) seeded alongside "Preview
  Studio", reachable from the business marketing page; the preview middleware selects the fixture
  by path (`/preview/business/*`). Requires I-1.1.
- **Done when**: preview tests cover read access and write rejection for the business fixture.

### Step B-12.4 — Super admin segmentation [V1]
- Platform analytics and the org list filter by `type` and `plan`; new metrics: business signups,
  onboarding completion rate, time-to-first-post, weekly active brands, conversion free → paid.
- **Done when**: analytics tests cover the type filter and each new metric with fixtures.

---

## Phase B-13 — Hardening, docs & launch [MVP before public launch]

### Step B-13.1 — Abuse and cost safeguards [MVP]
- Signup: email verification required before connecting accounts or using AI; per-IP signup
  throttle; disposable-email blocklist (config). Managed publishing: per-org daily post cap as a
  safety ceiling independent of plan; alerting when a single org's failure rate spikes.
- **Done when**: tests cover each guard; logs include `org_id` without PII beyond what's needed.

### Step B-13.2 — Data export and account deletion [MVP]
- Self-serve "Export my data" (posts, captions, media links, analytics CSV) and "Delete workspace"
  (Owner, typed confirmation, 14-day grace, then a queued job deleting org data and revoking the
  managed publish profile).
- **Done when**: tests cover export contents, the grace period, cancellation, and full deletion
  including storage files (faked).

### Step B-13.3 — Module docs [MVP]
- Finalise `.junie/modules/business-workspaces.md`; update `organizations-users.md`, `clients.md`
  (`is_primary`), `posts.md` (`PostWorkflow`), `publishing.md` (`PublishCredentials`), `ai.md`,
  `analytics.md`, `billing.md` (subscriptions vs. client invoices), `staff.md`, `preview-mode.md`,
  `super-admin.md`, and the README status table. (Each step above also updates its own docs as it
  lands; this step is the final consistency pass.)
- **Done when**: `sync-module-docs` reports no drift.

### Step B-13.4 — Launch checklist [MVP]
- Full quality gates (`graphy-checks`), a browser walk-through of both personas (Jess on a phone
  viewport, Jack on desktop with three platforms), the convention and UI reviewers on the full
  diff, and a seeded load test of the Today page and scheduler with 1,000 business orgs.
- **Done when**: all gates are green and both walk-throughs end with a published post.

---

## 4. Sequencing & MVP cut

```
I-1.x prerequisites ──► B-0 ──► B-1 ──► B-2 ─┐
                                    └──► B-3 ─┼──► B-4 ──► MVP launch (+ B-5, B-9.1–9.2, B-13)
                         B-5 (can start after B-1) ┘
V1:      B-6, B-7.1–7.2, B-8, B-9.3–9.4, B-10.1–10.2, B-11.1, B-12
Stretch: B-6.5, B-7.3–7.4, B-10.3, B-11.2–11.5
```

**MVP** (Jess can run her bakery's social media alone): B-0, B-1, B-2, B-3.1–3.2, B-4.1–4.4, B-5,
B-9.1–9.2, B-13.

**V1** (competitive with Buffer/Later for small businesses and creators): everything tagged [V1],
notably AI ideas/captions, the PWA quick-post, insights and paid plans.

Rough sizing: Phases B-1 to B-5 are mostly composition over existing Actions; the riskiest pieces are
B-3.1 (workflow contract touches the post state machine) and B-5.1 (managed publishing credentials
and webhook routing). Give both a dedicated review with `graphy-convention-reviewer` and a regression
run of the full agency suite.

---

## 5. Open questions (resolve in B-0.1)

1. **Naming.** Internal enum `OrganizationType::Business` is assumed. Public name: "Alima for
   Business & Creators"? One product with two signups, or a separate sub-brand?
2. **Free tier.** Is there a free plan (e.g. 3 accounts, 30 scheduled posts/month, small AI
   allowance), or trial-only? This sets the managed-publishing cost exposure.
3. **Upload-Post managed mode.** Confirm Upload-Post's commercial terms and API for sub-profiles under
   one platform key (per-profile isolation, webhook routing, per-profile limits). If not viable, B-5.1
   keeps the contract and swaps the vendor (Phase 4 adapter).
4. **Vision AI provider.** DeepSeek's vision support vs. using Gemini (already integrated for images)
   for B-6.2.
5. **Holiday/event data.** Static dataset per country vs. a paid API; which countries at launch?
6. **Creator platforms.** YouTube Shorts and Threads are high priority for creators; are they in the
   adapter rollout before V1?
7. **Agency → business downgrade.** Confirmed out of scope?
8. **Data retention** for free workspaces that go inactive (storage cost of media).

---

## 6. Exit criteria for this plan

- A new user can choose "I manage my own brand", sign up, connect accounts, and schedule a post from
  their phone without seeing a single agency concept or API key field.
- Business and agency orgs share one engine: no duplicated publishing, AI or analytics code; every
  difference sits behind `CurrentWorkspace`, `PostWorkflow`, `PublishCredentials` or `Entitlements`.
- Agency behaviour is unchanged (full agency regression suite green after every phase).
- Plan limits and AI allowances are enforced in Actions with tests per limit.
- Module docs describe the business workspace as built.
