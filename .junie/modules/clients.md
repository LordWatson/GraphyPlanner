# Client (CRM) Model

## Purpose
The Client is the core CRM record agencies manage brands/campaigns/posts under. Covers Step 0.3
(model + CRUD UI) and Step 0.4 (computed Red/Amber/Green health status).

## Models / Tables
- `Client` (`clients`): `org_id` (FK), `name`, `legal_name`, `website`, `industry`,
  `countries` (json array of ISO codes), `status` (`App\Enums\ClientStatus`: active, paused,
  offboarding, archived), `owner_user_id` (FK to `users`, nullable), `start_date`, `retainer_amount`
  (decimal, nullable), `billing_cycle` (`App\Enums\BillingCycle`: monthly, quarterly, annual,
  project_based; nullable), `tags` (json array), `default_language`, `notes_internal` (text,
  nullable), `approval_email` (nullable). `belongsTo` `Organization` and `owner` (`User`).
  Step I-2.5: `health_status` (`ClientHealth`, nullable), `health_reason`, `health_computed_at` —
  the materialized output of `ClientHealthService`, indexed `(org_id, health_status)`; also
  `(org_id, status)` and (Step I-2.1) `(org_id, name)`.
- `ClientHealthHistory` (`client_health_history`, Step I-2.5): `client_id`, `health_status`,
  `health_reason`, `created_at` — one row per change, written by `RecomputeClientHealthAction`;
  `Client::healthHistory()`. Feeds the health sparkline planned in Step I-6.1.
- `ClientHashtag` (`client_hashtags`, Step I-2.6): see `posts.md`.
 - `DailyBrief` (`daily_briefs`): `client_id` (FK), `date` (unique per client), `summary` (text, nullable),
   `trends` (json), `ideas` (json), `created_by` (FK `users`, nullable), timestamps. Represents the
   Step 2.5 daily AI summary stored per client per day.

## Actions / Services / Contracts
- `App\Actions\Clients\CreateClientAction`: creates a client scoped to the acting user's `org_id`,
  wrapped in `DB::transaction`, logs creation.
- `App\Actions\Clients\UpdateClientAction`: updates a client, wrapped in `DB::transaction`, logs the
  update.
- `App\Services\ClientHealthService`: the rule engine for a client's `App\Enums\ClientHealth`
  (`red`/`amber`/`green`) status plus a human-readable reason. Step I-2.5: its result is
  **materialized** on `clients.health_*` by `RecomputeClientHealthAction`; `Client::health(): array{
  status: ClientHealth, reason: string}` reads the stored columns (computing live only for a row
  never computed). Rules are evaluated red-first, then amber, falling back to
  green; each is independently covered in `tests/Unit/Services/ClientHealthServiceTest.php`:
  - **Red**: `offboarding` status; OR `active` with no `owner_user_id`; OR `active` with both
    `retainer_amount` and `billing_cycle` missing; OR the client has any `SocialAccount` whose
    `connection_status` is `token_expired` (Step 1.6 — regardless of `status`, since a client can't
    publish while a linked account's vendor token is expired/revoked).
  - **Amber**: `paused` status; OR `active` with no `start_date`; OR `active` with exactly one of
    `retainer_amount`/`billing_cycle` set (incomplete billing setup).
  - **Green**: `archived` status, or any `active` client that hits none of the above.
  - These rules currently only draw on fields already present on `Client` plus `SocialAccount`
    (Step 1.6). Once Post/PostTarget (0.8, already exists) signals are worth including, revisit this
    list to fold in things like stale posting cadence or overdue approvals per the original spec §4.2.
  - `hasTokenExpiredAccount()` uses `Client::loadMissing('socialAccounts')`, so callers that already
    eager-load `socialAccounts` (list pages) avoid N+1 queries and callers that don't (a single
    `Client::health()` call) still work correctly.
- `App\Actions\Clients\RecomputeClientHealthAction` (Step I-2.5): re-runs `ClientHealthService`
  with fresh, unscoped social accounts, stores the result quietly (no events, `updated_at`
  untouched), writes a `client_health_history` row and busts the org's analytics cache when it
  changed (logs `Client health changed`). Triggers: `Client::saved` when a rule input changes
  (`Client::HEALTH_INPUTS`: status, owner, start date, retainer, billing cycle) or on create;
  `SocialAccount` saved (connection status / client changed, or created), deleted and restored;
  and the nightly `clients:recompute-health` sweep (`RecomputeClientHealthCommand`, 02:30,
  `withoutOverlapping()->onOneServer()`, heartbeat-tracked).
- `App\Actions\Clients\ListClientsAction` (Step I-2.5): the roster — paginated (25/50/100), search
  (name, legal name, tags), filters (status, health, owner, tag) and sorts (name, health, owner,
  start date, retainer) all in SQL. Retainer sort is ignored (name order) for viewers without
  `viewBilling`, so ordering can't leak amounts. `healthCounts()` gives the header's split.
- `App\Actions\Clients\BuildClientPageHeaderAction` (Step I-2.4): the props every client tab shares
  — client, abilities, tab key and stat cards, all SQL aggregates (one `withCount` query, a
  per-currency sum of sent invoices, a briefs count). **MOVE 5 (ux-overhaul, 2026-10-24):** also
  returns a `setup` payload — the new-client checklist signals `{brandBrain, accounts, portal,
  content, complete}`. `brandBrain` is true once any core Brand Brain field (voice tone/personality,
  audience description, offer value proposition, or content pillars) is filled; the other three reuse
  the `withCount` aggregates. The frontend `ClientSetupChecklist`
  (`components/clients/client-setup-checklist.tsx`) renders it on the client's landing (accounts)
  tab only, shows just the steps the viewer can act on, and hides once they are done.
- `App\Support\Clients\ClientPresenter` (`detail()`, `row()`) and `ClientTabPresenter`
  (`socialAccount`, `targetAccount`, `campaign`, `post`, `asset`, `invoice`, `invitation`): the
  array shapes formerly private to `ClientController`, reusable by other controllers.
- `App\Support\Options\LanguageOptions`: curated `{value, label}` list of ISO 639-1 language codes,
  used to populate the `default_language` dropdown on the client create/edit forms.
- `App\Policies\ClientPolicy`: see `organizations-users.md` for the full access rules; registered via
  `Gate::policy` in `AppServiceProvider`.
- `Client::invoices()`: `hasMany` `Invoice`. See `billing.md` for the full invoicing feature (create/
  send/mark paid), which is surfaced as a "Billing history" section on the client show page.
- `Client::invitations()`: `hasMany` `ClientInvitation`. See `client-portal.md` (Step 6.1) for the
  full invite/revoke feature, surfaced as a "Portal access" section on the client show page.

## Phase M-6 additions (Step M-6.3 — approval policy)
- `clients.approval_policy` (`ApprovalPolicy`: `any` / `all` / `sequential`, default `any`; migration
  `2026_11_05_100100`, in `Client` fillable + casts, edit-form "When there are several approvers").
  Drives how a post's `approval_steps` are satisfied across the client's approver contacts
  (`Client::approverContacts()` = review-email contacts, in add order). See `client-portal.md` › M-6.3.

## Phase M-6 additions (Step M-6.1 — client contacts)
- `client_contacts` (migration `2026_11_04_100100`): `org_id`, `client_id`, `name` (nullable),
  `email`, `phone` (nullable), `role` (`ClientContactRole`: approver / billing / marketing_lead /
  other), `receives_review_emails`, `receives_invoices`, timestamps, soft deletes. FK columns lead
  indexes (`org_id`; `client_id,role`). Live `(client, email)` uniqueness is enforced in the Form
  Requests, not the DB (a UNIQUE including nullable `deleted_at` would not constrain live rows on
  MySQL or SQLite). `ClientContact` uses `BelongsToOrganization` (org scope + policy-guarded binding).
- Backfill `2026_11_04_100200` migrates the legacy single-address columns into contacts: a client's
  `approval_email` → an Approver contact receiving review emails, its `billing_email` → a Billing
  contact receiving invoices, merged into one row when both addresses are equal. Idempotent. The
  legacy `clients.approval_email` / `billing_email` columns are **kept** as the fallback (not synced
  forward), so nothing that still reads them breaks.
- `Client::contacts()`, `Client::reviewEmailRecipients()` (approver-style contacts opted into review
  emails, else the legacy `approval_email`) and `Client::invoiceRecipients()` (billing contacts opted
  into invoices, else `invoiceRecipientEmail()`). Review-email read paths now fan out to every
  recipient: `SendClientReviewRequestedEmailAction`, `SendClientGridReviewRequestedEmailAction`,
  `BulkUpdateCampaignPostsAction::emailClientOnce`, `MoveGridPostsAction::emailClientOnce`.
  `ClientReportRecipients` merges review-email contacts; `InvoiceDeliveryService::resolveRecipient`
  prefers the first billing contact (invoices still record a single `sent_to_email`).
- Actions `CreateClientContactAction` / `UpdateClientContactAction` / `DeleteClientContactAction`
  (soft delete), `ClientContactPolicy` (Owner/Strategist in the client's org; `before` denies a
  business workspace), `ClientContactController` + `StoreClientContactRequest` /
  `UpdateClientContactRequest` (`payload()` — not `data()`, which collides with
  `Request::data()`). Routes `clients.contacts.store`, `contacts.update`, `contacts.destroy`
  (agency-only). Managed inline on the Portal access tab (`ClientContactsPanel`), above invitations.
- Tests: `tests/Feature/Clients/ClientContactTest.php` (backfill parity + idempotency, CRUD, authz,
  review emails reach every approver, legacy fallback, contacts preferred over legacy, org scope).

## Phase M-3 additions (Step M-3.4 / I-6.1 — client health v2; M-3.3 report schedule)
- `ClientHealthService::compute(Client, ?now)` now returns `reasons`: every signal that fired as
  `{key, tone, weight, label, owner_only}`, heaviest first. Health = the worst tone; the headline
  `reason` = the heaviest (advisory reasons never headline). Signals and weights: offboarding 100,
  token expired 95, no owner 90, no billing 85, ≥3 failed/partly published posts in 7 days 80 (else
  65 amber), overdue approval due within a day 78 (else 60 amber; waiting > 48 h), overdue invoice
  > 30 days 76 (else 58 amber; **Owner-only**, counts and ages — never amounts), paused 70, token
  expiring 55, cadence below 75% of `target_posts_per_week` over 28 days 50, nothing planned in
  the next 7 days 40, partial billing 30, no start date 20, Brand Brain gaps 10 (**advisory**,
  green: listed, never changes health). Thresholds in `config/clients.php` (`health`). Cadence,
  calendar gap and Brand Brain only apply to active clients; archived clients have no reasons.
- Stored in `clients.health_reasons` (migration `2026_10_23_100300`, which also adds
  `clients.target_posts_per_week`, editable on the client form). `RecomputeClientHealthAction`
  keeps the list current; `client_health_history` still records headline changes only.
- `Client::healthFor(?User)` — the stored status with the reasons the viewer may read (Owner-only
  dropped for non-Owners; if one is the headline they get the next visible reason, or "Needs
  attention — an Owner has the details."). Used by `ClientPresenter` (roster + header, now with
  `health_reasons`, `health_trend` (90 days from history) and `target_posts_per_week`), the
  dashboard health card and organization analytics.
- Freshness: `RecomputeClientHealthJob` (unique per client for a minute) is queued when a post's
  status, an invoice's status/due date, or the Brand Brain changes; the `clients:recompute-health`
  sweep is now **hourly** for time-based signals.
- Frontend: `components/clients/health-reasons.tsx` (`HealthReasons` popover: weighted list,
  "Also worth a look", 90-day trend strip) in the client header and the client analytics panel;
  the roster's health pill title lists every reason.
- `clients.report_schedule` (monthly client reports) — see `analytics.md` › Client monthly reports.

## Phase M-2 additions

- `clients.default_approval_mode` (default `client_required`; edit form "New posts need") feeds new
  posts' approval mode (M-2.9).
- Health: an Amber rule for a connection expiring within 7 days / expired that couldn't be refreshed
  ("The Instagram connection for @x expires in 5 days — reconnect it.", M-2.7);
  `RecomputeClientHealthAction` loads the token columns, and an account's `token_expiry_warning`
  change re-materializes health.
- Accounts tab: invite links panel, per-account settings and health/expiry lines (M-2.6–M-2.8).

## Phase M-1 additions
- `DELETE clients/{client}/purge` validates `PurgeClientRequest` (`forceDelete` policy +
  `password` = `current_password`). The archived list asks for it with `confirmWithPassword()`.
- The roster and the archived list render through the shared `DataTable` (server-side sort on
  name / owner / health via the existing `ClientIndexRequest` sorts; column visibility remembered
  per browser). The Posts tab uses it too (each row opens the editor).

## Routes / Controllers
- `Route::resource('clients', ClientController::class)` (under `auth`+`verified` middleware):
  - `GET /clients` → `index` — `ClientIndexRequest` + `ListClientsAction`; props `clients`
    (paginator of `ClientPresenter::row`), `filters`, `filterOptions`, `healthCounts`,
    `archivedCount`. Billing fields redacted per `viewBilling`.
  - `GET /clients/create` → `create` — Inertia form.
  - `POST /clients` → `store` — validated via `StoreClientRequest`, delegates to
    `CreateClientAction`.
  - `GET /clients/{client}` → `show` — the **Social accounts** tab (`clients/tabs/accounts`).
  - `GET /clients/{client}/edit` → `edit`.
  - `PUT /clients/{client}` → `update` — validated via `UpdateClientRequest`, delegates to
    `UpdateClientAction`.
  - `DELETE /clients/{client}` → `destroy` (Owner only).
- Step I-2.4 — one route per client page tab (`ClientTabController`, each spreading
  `BuildClientPageHeaderAction`'s props; all mirrored under `/preview`):
  - `GET /clients/{client}/posts` → `posts` (`clients.posts.index`) — `ClientPostsTabRequest`
    (`search`/`status`/`campaign`/`platform`) → `ListClientPostsAction`, 8/page; also sends
    `filters`, `filterOptions` (statuses, the client's campaigns) and `targetAccounts`.
  - `GET /clients/{client}/media` → `media` (`clients.media`) — header props only (no asset rows);
    the tab uploads via `clients.assets.store` and browses/removes through the paged JSON
    `clients.assets.index` library dialog (see `assets.md`, 2026-10-18).
  - `GET /clients/{client}/campaigns` → `campaigns` (`clients.campaigns.index`) — 25/page.
  - `GET /clients/{client}/billing` → `billing` (`clients.billing`) — `ClientBillingTabRequest`
    (`viewBilling`; `search`/`status`) → `InvoiceLedgerService::query` scoped to the client,
    `INVOICES_PER_PAGE` = 8; also sends `filters`.
  - `GET /clients/{client}/portal-access` → `portalAccess` (`clients.portal-access`) — invitations.
  - `GET /clients/{client}/ai` → `ai` (`clients.ai.index`) — Owner/Strategist + `ai.enabled`;
    `trends`/`ideas` are `Inertia::defer` props.
  - `GET /clients/{client}/briefs` → `briefs` (`clients.briefs`) — same gate; the list is fetched
    from `GET /clients/{client}/daily-briefs` (`DailyBriefController@index`, JSON).
  - Creating/removing a campaign, asset, post, invitation or invoice now redirects to its tab.
- Inertia pages: `resources/js/pages/clients/{index,create,edit}.tsx` and
  `resources/js/pages/clients/tabs/{accounts,posts,media,campaigns,billing,portal-access,ai,briefs}.tsx`.

## Frontend
- `resources/js/pages/clients/index.tsx` opens with a gradient-brand hero banner ("Client roster"
  headline with `text-gradient-brand` treatment + subtitle) above the client table, matching the
  dashboard's hero pattern; the table itself stays flat per the data-dense-screen rule.
- Step I-2.5: the roster has a toolbar (debounced search, status/owner selects, sort, page size),
  clickable health chips that filter, a tag chip, and the shared `components/pagination.tsx`.
- Step I-2.4: the 2,621-line `clients/show.tsx` is gone. `components/clients/client-layout.tsx`
  (`ClientLayout`, `TabPanel`, `TabHeading`) renders the header, internal notes, archive dialog,
  section boxes (stat cards, plus a "More" menu) as **links**; each tab is its own page under `pages/clients/tabs/`
  (all under ~400 lines). Paged tabs use `router.reload({ data: { page }, only: [...] })`. The AI
  tab's list card and refresh call live in `components/clients/ai-insights.tsx`.

## Seeders
- `Database\Seeders\ClientSeeder`: creates three sample clients (`Acme Corp` active, `Nimbus Studios`
  paused, `Blue Harbor Cafe` offboarding) under the "Graphy Agency" organization owned by
  `test@example.com`, and a `reviewer@acme.test` user with the `client_reviewer` role scoped to
  `Acme Corp` (password `password`).
- Called from `DatabaseSeeder` right after `OrganizationSeeder`, so the organization already exists.

## Business Rules
- Clients are always created under the acting user's `org_id`; users cannot create/view clients
  belonging to another organization.
- `retainer_amount`/`billing_cycle` are hidden from the JSON payload sent to the frontend whenever the
  viewer fails `ClientPolicy::viewBilling` (Designer, Viewer, Client reviewer).
- The client show page's "Billing" and "Portal access" tabs/stat cards are only rendered when
  `can.viewBilling` (`ClientPolicy::viewBilling`) / `can.viewInvitations`
  (`ClientInvitationPolicy::viewAny`) are true, i.e. Owner/Strategist only — Designer, Viewer, and
  Client reviewer never see those tabs on the show page, not just an empty list inside them.
- Only `Owner` can delete a client. Step I-1.5: "delete" **archives** — `ArchiveClientAction`
  sets `status = archived` and soft-deletes (`clients.deleted_at`). An archived client leaves every
  list and its pages 404; its publishing (`PublishPostJob` skips it, post stays `scheduled`) and
  reminders pause; its review links 404, its portal 403s and its invitations can't be accepted.
  Posts, approvals, activity logs and **invoices are all kept** (child→client relations use
  `withTrashed()`, so an invoice still shows its client). `Post::forActiveClients()` hides its
  posts from Home, calendar, dashboard and reminders (analytics keeps history).
- Restore: `POST clients/{client}/restore` (`RestoreClientAction`, Owner, `ClientPolicy::restore`)
  brings it back as `active`. Listed under `GET clients?archived=1` (the roster's "Archived" button).
- Purge: `DELETE clients/{client}/purge` (`PurgeClientAction`, Owner, `ClientPolicy::forceDelete`,
  archived clients only). Blocked while any invoice exists — in the action (validation error) and
  in the database (`invoices.client_id` is now `NO ACTION` instead of `CASCADE`). Deletes the
  client's portal logins, hard-deletes the client (cascades remove posts/assets/campaigns/…) and,
  after commit, removes its stored files via `AssetStorage` (`StoredFileInventory`).
- Step I-2.5: health is read from the stored columns everywhere (roster, client header, dashboard
  health card, org analytics), so it sorts/filters in SQL. The rules still only use client fields
  and social accounts; post-failure and overdue-invoice signals arrive with Step I-6.1 and must add
  their own recompute triggers then.
- `health`/`health_reason` are always included in the client payload sent to the frontend (they are
  not billing data, so they aren't gated behind `viewBilling`).

## Tests
- `tests/Feature/ClientControllerTest.php`: full CRUD + authorization + billing redaction through the
  UI/controller.
- `tests/Feature/ClientPolicyTest.php`: policy-level coverage per role.
- `tests/Feature/Clients/ClientRosterTest.php` (Step I-2.5): pagination, search, every filter, health
  and retainer sorting (incl. the non-billing fallback), stored-health reads, recompute on client
  change and on account token expiry/removal, and the nightly sweep repairing drift.
- `tests/Feature/Clients/ClientTabsTest.php` (Step I-2.4): each tab's component + paginated props,
  header counts, the deferred AI props and gates, cross-org refusal, and the overview/posts props
  staying under 100 KB for a client with 2,000 posts.
- `tests/Unit/Services/ClientHealthServiceTest.php`: each Red/Amber/Green trigger independently,
  including the Step 1.6 `token_expired`-`SocialAccount` Red trigger.
- `tests/Feature/SocialAccountHealthAcceptanceTest.php`: the full §12 acceptance scenario — running
  `social-accounts:check-health` against a disconnected vendor token flips the account to
  `token_expired`, the client's health to Red, and produces a Home row.
 - Step 2.5:
   - `tests/Unit/Actions/AI/CreateDailyBriefActionTest.php`: idempotency per client/date.
   - `tests/Feature/DailyBriefControllerTest.php`: authorization and scoping for briefs routes.
   - `tests/Feature/GenerateDailyBriefsCommandTest.php`: one job per active client when command runs.

## Known Gaps / Open Questions
- An archived client always restores to `active` (its previous status isn't remembered).
- Soft-deleted assets keep their files until the client/org is purged — no time-based prune yet.
- The exact §4.2 health rules from the original spec weren't available in this repo; the current
  rules are a reasonable interim definition based solely on existing `Client`/`SocialAccount` fields
  and should be revisited once BrandBrain/Post signals are worth folding in (see the note above).
- (Resolved 2026-09-24) Client deletion now requires typing the client's name in a confirmation
  dialog before the destroy button is enabled.
- (Resolved 2026-09-17) `owner_user_id` now has a UI picker on the client create/edit forms.

## Changelog
- 2026-10-24: UX overhaul MOVE 5 (`.junie/plans/ux-overhaul.md`) — `BuildClientPageHeaderAction`
  now returns a `setup` payload and the client's landing tab shows a guided `ClientSetupChecklist`
  (Brand Brain → connect accounts → invite the client → first post), ticking off from server
  signals and hiding once done. Tests in `ClientTabsTest` (fresh client incomplete; steps tick off
  and complete). Frontend-first + one Brand Brain read; no schema change.
- 2026-10-22: Step B-4.5 — added `clients.posting_goal` (nullable JSON: `{posts_per_week,
  preferred_days}`), the primary brand's weekly posting goal for a business workspace. **Separate**
  from the single-integer `target_posts_per_week` health input; read it via `Client::postingGoal()`
  (the `App\Support\Workspace\PostingGoal` value object). See `.junie/modules/business-workspaces.md`.
- 2026-10-24: Steps M-4.1 / M-4.3 — the client gained billing-gated `tax_registration_number`,
  `billing_currency` (its own, falls back to the org default — `Client::billingCurrency()`),
  `default_tax_rate_id` (`defaultTaxRate()`, applied to the retainer + new fixed-amount/campaign
  fees) and `auto_invoice_retainer` (opt-in to recurring retainer drafts). All on the client edit
  form (currencies + the org's active tax rates), in `ClientPresenter::detail`, and
  `Store`/`UpdateClientRequest`. See `billing.md`.
- 2026-10-21: Step M-3.4 (I-6.1) — client health v2: weighted reasons (failed publishes, overdue approvals, cadence vs `target_posts_per_week`, calendar gaps, Owner-only overdue invoices, advisory Brand Brain gaps), `health_reasons`, `Client::healthFor()`, 90-day trend, queued recompute + hourly sweep. Step M-3.3 adds `report_schedule`. `ClientHealthServiceTest` gains a case per signal and the weight ordering. Fixed: the service no longer caches `brandBrain` on the caller's model.
- 2026-10-20: The Grid section box moved from the visible row into the "More" menu (listed first),
  so the row is back to five boxes: Social accounts, Campaigns, Posts, Assets and More.
- 2026-10-20: Phase M-2 — default approval mode, token-expiry Amber rule, Accounts tab additions.
- 2026-10-18: Campaigns enhancement — `clients.approval_lead_days` (working days, default 3) drives deliverable due dates. The Campaigns tab uses `CampaignPresenter` + batched health, and offers up to three templates (`starterTemplates`) while the client has no campaigns (C-9.3).
- 2026-10-18: Posts tab — the inline create form is replaced by a header row matching Billing:
  "View calendar" (the org calendar filtered to `client_id`) and a "New post" button opening
  `components/posts/new-post-dialog.tsx` (`NewPostDialog`: caption, target accounts, one local
  date/time applied to every target, optional campaign; posts to `clients.posts.store`, so new
  posts still start as `idea`).
- 2026-10-18: Posts and Billing tabs are filtered server-side and paged 8 at a time. Posts: caption
  search (literal, `%`/`_` escaped), status, campaign, channel. Billing: status (All / Draft /
  Outstanding / Overdue / Paid / Void, same meaning as the org ledger) and number/description
  search. Filter controls (`components/clients/tab-filters.tsx`: `visitClientTab`,
  `TabSearchFilter`, `TabSelectFilter`, `TabSegmentedFilter`) follow the Trends & Ideas tab's
  labelled-filter style; each change is a partial GET reload (`only` the list + `filters`) and
  the pager now sits inside the tab panel. Unknown filter values fail validation.
- 2026-10-18: The client page's text tab row is gone; the stat boxes are now the only section
  navigation (a `<nav aria-label="Client sections">` row of `SectionCard`s in `ClientLayout`). Four
  boxes stay visible: Social accounts, Campaigns, Posts and Assets. The fifth, "More" box (it used
  to be "More stats") opens a menu of real links to every other section: Billing (outstanding),
  Portal access, Trends & Ideas, Daily Briefs, and Analytics, which previously had no box. Each
  entry shows only when the viewer can open that section. When one of those sections is open, the
  "More" box takes on its icon, label and figure in the active style. To show the boxes are
  clickable without a hover-lift (brand §5), a hovered or keyboard-focused box gets a teal-tinted
  border and surface (`bg-accent/40`), its icon tile fills teal (Lime in dark mode), and a small
  arrow slides in beside the label. Reduced-motion users get the arrow without the slide. While its
  menu is open, the "More" box keeps the same tint and its chevron points up.
- 2026-10-18: Steps I-2.4/I-2.5 — client page split into per-tab routes (`ClientTabController`,
  `BuildClientPageHeaderAction`, `ClientPresenter`/`ClientTabPresenter`, `ClientLayout` +
  `pages/clients/tabs/*`); roster paginated/searched/filtered/sorted in SQL (`ListClientsAction`,
  `ClientIndexRequest`); health materialized (`clients.health_*`, `client_health_history`,
  `RecomputeClientHealthAction`, `clients:recompute-health`). Corrected the stale Daily Briefs
  route notes (the JSON endpoint is `/clients/{client}/daily-briefs`).
- 2026-10-18: Step I-1.5 — archive instead of delete (soft deletes on `Client` and its records),
  `clients.restore` / `clients.purge` routes and actions, "Archived" view on the roster (Restore /
  Delete permanently, the latter hidden while invoices exist), "Archive" on the client page with a
  plain confirm instead of the type-the-name dialog. `ClientController::destroy`'s inline delete
  moved into `ArchiveClientAction`. Tests: `tests/Feature/Clients/ClientArchiveTest.php`.
- 2026-10-18: Phase 17 — an "Analytics" link in the client page's tab row opens `GET /clients/{client}/analytics` (the org analytics scoped to this client, with its campaigns; gated by `ClientPolicy::view` + `OrganizationPolicy::viewAnalytics`). See `analytics.md`.
- 2026-10-16: Raw ISO dates on `clients/show.tsx` (the "Since" chip, campaign ranges, post
  schedules) and the AI trend/idea eyebrows now render via the shared `lib/dates.ts` helpers.
- 2026-10-16: Brand-book overhaul (UI only): client pages belong to chapter 04 in the running head;
  `clients/show.tsx` titles the client with a Deep Teal monogram tile beside the name; the roster's
  eyebrow drops the duplicated "Clients —" prefix; create/edit headers use the brand voice ("Add a
  client.").
- 2026-10-16: Clients gained `billing_email` and `billing_address` (nullable; validated in `StoreClientRequest`/`UpdateClientRequest`, editable on the create/edit forms, exposed by `ClientController::transform()` only to users passing `viewBilling`). `Client::invoiceRecipientEmail()` returns the billing email, falling back to `approval_email`. The Billing tab on `clients/show.tsx` now uses the shared invoice ledger list, a "New invoice" dialog, and Send/Mark-paid dialogs; its "Outstanding" stat counts only `sent` invoices (it previously included drafts). See `billing.md`.
- 2026-10-14: Follow-up — the stacked multi-row stat-card grid from the previous changelog entry
  still looked "tacky and unorganised" once it grew past a handful of cards. Replaced it with a
  single, non-wrapping row: the four core cards (Social accounts, Campaigns, Posts, Assets) stay
  always visible, and the rest (Outstanding, Portal access, Trends & Ideas, Daily Briefs) are tucked
  behind a "More stats" `DropdownMenu` trigger card at the end of the row (shadcn
  `components/ui/dropdown-menu`) — each menu item still shows its icon/value/hint and jumps to the
  matching tab on click, so no information is lost, it's just revealed on demand instead of forcing
  a long grid onto the page.
- 2026-10-14: The client show page's stat cards and tab list were getting long/messy as features
  were added (now up to 9 stat cards and 8 tabs with Trends & Ideas / Daily Briefs). Added the two
  missing stat cards ("Trends & Ideas" with a combined trend+idea count, "Daily Briefs" with the
  fetched brief count — fetched eagerly on mount via `fetchDailyBriefs()` instead of only on tab
  open, so the count is accurate right away) and tidied the layout: the stat-card grid now uses a
  fixed responsive column count (`grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5`) with
  more compact cards instead of per-count `lg:grid-cols-N` logic, and the tab list now groups
  related triggers (Content / Billing & Portal access / AI insights) with a thin vertical divider
  between groups for a tidier, still-modern look.
 - 2026-10-14: Step 2.5 — Added Daily Briefs: model/migration, action, job + command/scheduler,
   Owner/Strategist-only UI under Clients, and notification to Owners/Strategists (quiet hours respected).
- 2026-09-24: The client show page's Delete button no longer submits immediately; it now opens a
  `Dialog` (shadcn `components/ui/dialog`) that requires typing the client's exact `name` into a text
  input before the destructive "Delete client" button is enabled. Confirming calls `router.delete`
  against the existing `destroy` route directly (replacing the previous `Form`/`destroy.form` submit),
  matching the brand guideline that destructive actions need explicit confirmation. No backend changes
  were needed.
- 2026-09-17: Added an "Owner" `Select` dropdown to the client create/edit forms, backed by a new
  `owners` prop (`ClientController::ownerOptions()`, org-scoped `users` list of `{value, label}`)
  passed from `create`/`edit`. Uses a `"none"` sentinel option ("No owner") converted to an empty
  string via the `Form` component's `transform` prop before submission, since Radix `Select` doesn't
  support empty string values; no backend changes were needed as `owner_user_id` was already validated
  (org-scoped `exists` rule) and persisted by `StoreClientRequest`/`UpdateClientRequest` and the
  create/update Actions.
- 2026-09-17: `default_language` on the client create/edit forms is now a `Select` dropdown backed by
  `App\Support\Options\LanguageOptions` (a curated ISO 639-1 list) instead of a free-text `Input`,
  passed to both pages as a `languages` prop from `ClientController::create`/`edit`.
- 2026-09-16: Redesigned `resources/js/pages/clients/show.tsx`'s section navigation: the five
  collapsible sections (Social accounts, Campaigns, Posts, Assets, Billing history) were replaced
  with a `Tabs`/`TabsList`/`TabsContent` layout (shadcn `components/ui/tabs`), and the stat cards in
  the hero row are now clickable buttons that jump straight to the matching tab. This removes the
  "what actions are available" ambiguity of the old fold/unfold buttons and keeps long lists (posts,
  campaigns) from pushing other sections out of view. Content/forms inside each section are
  unchanged.
- 2026-09-16: `create.tsx`/`edit.tsx`/`show.tsx` switched from the action-based
  `ClientController.*.form(...)` Wayfinder import to named `@/routes/clients` imports, fixing a
  `.form is not a function` crash caused by preview mode mirroring these routes (see
  `preview-mode.md`).
- 2026-09-08: Added `Client::invoices()` relation and wired invoice history/create form into the
  client show page; see `billing.md` for the full feature.
- 2026-09-08: Initial `Client` model, migration, policy, actions, controller, and CRUD Inertia pages
  (Step 0.3), built alongside Step 0.2's org/role foundation.
- 2026-09-08: Added `ClientSeeder` (3 sample clients + a scoped client reviewer user) and wired it
  into `DatabaseSeeder`.
- 2026-09-08: Added `ClientHealthService`/`ClientHealth` enum and `Client::health()` accessor (Step
  0.4); wired `health`/`health_reason` into `ClientController::transform` and rendered a health pill
  on the clients index/show pages.
- 2026-09-23: Step 1.6 — `ClientHealthService` now returns Red for any client with a `token_expired`
  `SocialAccount`; `ClientController::index` and `GetClientHealthSummaryAction` (dashboard) now
  eager-load `socialAccounts` to keep this N+1-free. See `social-accounts.md`/`publishing.md` for
  the new `social-accounts:check-health` sweep that drives `token_expired`.
- 2026-09-09: Added a gradient-brand hero header to the clients index page and strengthened the
  existing hero/stat-card styling on the client show page, matching the agency-premium restyle
  applied to the dashboard, calendar, and post editor pages.
- 2026-09-19: The "Billing" and "Portal access" tabs/stat cards on `clients/show.tsx` were previously
  always rendered (with just their contents gated), so a Designer could see the tab labels/stat card
  headings even though the data inside was empty. Added `viewBilling`/`viewInvitations` to the `can`
  prop from `ClientController::show` and now hide the tab triggers, tab content, and stat cards
  entirely unless the corresponding ability is granted.
- 2026-09-19: The stat-card grid on `clients/show.tsx` used a fixed `lg:grid-cols-6`, so hiding the
  Billing/Portal access cards for Designer/Viewer/Client reviewer left an empty gap in the row instead
  of the remaining 4 cards stretching to fill it. The grid's column count is now derived from
  `statCards.length` (4/5/6 columns) so the visible cards always fill the row.
- 2026-10-20: `ArchiveClientAction` cancels the client's scheduled posts the vendor is already
  holding (`VendorScheduleService::releaseScheduled()`, best-effort); restoring sends them at their
  time. See `publishing.md`.
- 2026-10-20: Phase M-1: purge needs the Owner's password; roster, archive and Posts tab on `DataTable`.
