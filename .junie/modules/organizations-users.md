# Organizations, Users, Roles & Policies

## Purpose

Multi-tenant foundation: every `User` belongs to an `Organization` and has a `Role`. Policies enforce
the access rules described in spec §3 (Owner, Strategist, Designer, Client reviewer, Viewer). Covers
Step 0.2 of the plan.

## Models / Tables

- `Organization` (`organizations`): `name`, `slug`, `default_timezone`. Has many `users`, has many
  `clients`.
- `User` (`users`): adds `org_id` (FK to `organizations`, nullable), `role` (cast to `App\Enums\Role`),
  and `client_id` (nullable FK to `clients`, used only for the `client_reviewer` role to scope a
  reviewer to exactly one client). `belongsTo` `Organization` (`organization()`) and `Client`
  (`client()`).
- Step W-0.4: `users.phone` (E.164, nullable) + `users.phone_verified_at` (nullable) — the WhatsApp
  number. `User::routeNotificationForWhatsApp()` returns it **only** once verified, and only if it
  carries a country code; anything else is no route at all, so an unconfirmed or national number
  cannot be messaged. Written by Step W-1.1's panel on `settings/notifications`, with
  `phone_verification_code` (hashed), `phone_verification_sent_at` and
  `phone_verification_attempts` (migration `2027_04_23_100200`) carrying the one-time code: five
  wrong guesses discard it, ten minutes expire it, and changing the number clears the verification.
  See `notifications.md` › WhatsApp.
- Step W-4.1: `organizations.whatsapp_enabled`, `whatsapp_account_sid`, `whatsapp_auth_token`
  (encrypted + `Hidden`), `whatsapp_from`, `whatsapp_messaging_service_sid` (migration
  `2027_04_23_100100`) — the organization's own Twilio sender, resolved through
  `WhatsAppCredentials` and never read directly. `whatsapp_enabled` is the off switch that leaves
  the credentials in place.
- `App\Enums\Role`: `Owner`, `Strategist`, `Designer`, `ClientReviewer`, `Viewer` — string-backed enum
  with a `label()` helper.
- Step M-8.1: `organizations.is_design_partner` (boolean, default false, indexed) — the
  design-partner-offer flag (see `super-admin.md` for the usage view / coupon).
- Step M-8.2: `users.last_active_at` (nullable timestamp, indexed) — the weekly-active-users signal,
  stamped by `App\Http\Middleware\TrackUserActivity` (appended to the `web` group; writes at most once
  per 5 min per user via a cache guard, on `terminate` so it never slows a request; skips the org-less
  super admin and the read-only preview owner). Read by the partner usage view.
- Step M-8.2: `App\Models\ProductFeedback` (`product_feedback`) — in-app staff feedback, org-scoped
  via `BelongsToOrganization`; see `super-admin.md`.

## Actions / Services / Contracts

- None yet — role/org logic is currently expressed via the `Role` enum and `ClientPolicy` (see
  `clients.md`). Revisit if org/user management grows its own CRUD.

## Routes / Controllers

- No dedicated organization/user management routes yet (out of scope for Step 0.2 — the plan only
  asked for the data model, roles, and policy enforcement demonstrated on a sample controller).

## Seeders

- `Database\Seeders\OrganizationSeeder`: creates/updates the "Graphy Agency" organization, assigns
  the `Owner` role (in that org) to the `test@example.com` and `alexander.watson.work@gmail.com`
  users created earlier in `DatabaseSeeder`, and creates one sample user per remaining role
  (`strategist@graphy.test`, `designer@graphy.test`, `viewer@graphy.test`, all password `password`).
- Called from `DatabaseSeeder` right after `AlexanderWatsonUserSeeder` (so those two users already
  exist) and before `ClientSeeder`.

## Business Rules

- **Per-client staff access** (`organizations.restrict_staff_to_assigned_clients`, default **off**).
  When on, a non-Owner only sees the clients they are assigned on `client_user`. Two layers:
  `App\Models\Scopes\ClientAccessScope` (registered in `AppServiceProvider` across every
  client-owned model) filters lists and lookups, and a second `Gate::before` denies any ability on a
  record belonging to an unassigned client — needed because route-model binding resolves without
  scopes so a policy can answer 403. Owners and super admins are never restricted; a member
  assigned to nothing sees nothing (not everything). Assignments are edited on the staff member's
  page. See `clients.md` and finding 9 in `.junie/plans/market-readiness-audit.md`.
- A user's `org_id` scopes what data (e.g. clients) they can see; policies additionally gate by
  `role`.
- `client_reviewer` users are further scoped to a single `client_id`; they cannot list clients
  (`viewAny` is false) and can only `view` the one client they're attached to, with billing fields
  always hidden (see `ClientPolicy::viewBilling`).
- `Owner` has full access; `Strategist` can create/update but not delete; `Designer` and `Viewer` are
  read-only with no billing visibility.

### Tenant isolation (Step I-1.9)

- `App\Models\Concerns\BelongsToOrganization` + `App\Models\Scopes\OrganizationScope` on every
  org-owned model (`Client`, `Post`, `Campaign`, `Asset`, `SocialAccount`, `Invoice`,
  `InvoiceItem`, `AiTrend`, `AiPostIdea`, `AiImageGeneration`, `AiImageVariant`, `AiUsageLog`,
  `DailyBrief`, `OrganizationReport`, `StaffInvitation`, `StaffActivityLog`): every query is
  limited to the signed-in member's `org_id`. Skipped when nobody (or a super admin) is signed in —
  queue workers, scheduled commands, the webhook, token links, the super admin console. Cross-org
  on purpose: `->withoutOrgScope()`. `User` and `Organization` are not scoped.
- Route-model binding resolves **without** the scope, and child→parent `belongsTo` relations
  (`$post->client`, `$variant->generation`, …) are unscoped too: a record can always see its own
  parent, so policies inspect it and answer 403 rather than a 500 on a null relation. Policies stay
  the authority for single records; the scope protects lists and lookups.
- FormRequests authorize before they validate (`GenerateImagesRequest`,
  `ImageVariantActionRequest`, `UseImagesInPostRequest`, `TransitionPostRequest` with an invalid
  `to`), so another organization gets a 403 instead of validation errors.
- `exists:` rules that referenced org-owned tables without a tenant condition are scoped to the
  route's client (`StorePostRequest`/`UpdatePostRequest` campaign, assets, social accounts;
  `StoreAssetRequest` campaign; invoice item `post_id`) — before, another org's asset could be
  attached to a post.
- `tests/Feature/Security/CrossTenantRouteTest.php` walks `Route::getRoutes()`: for every route
  with a bound model it creates the models in org B and requests the route as each org-A role
  (Owner, Strategist, Designer, Viewer, ClientReviewer), expecting 403/404 (a client contact's
  `portal.redirect` 302 counts as refused). New routes are covered automatically; a route whose
  model has no org-B fixture fails with a message saying so. Verified 2026-10-18 that replacing
  `CampaignPolicy::view` with `return true` makes it fail.
  `tests/Feature/Security/OrganizationScopeTest.php` covers the scope itself.

## Tests

- `tests/Feature/ClientPolicyTest.php`: asserts allowed/denied actions (`viewAny`, `view`, `create`,
  `update`, `delete`, `viewBilling`) for every role directly against `ClientPolicy`.
- `tests/Feature/ClientControllerTest.php`: same access rules asserted end-to-end through
  `ClientController` (the "sample controller" required by Step 0.2's done condition).

## Known Gaps / Open Questions

- The full original spec §3 access table (beyond clients) isn't available in this repo; the
  Designer/Client reviewer/Viewer restrictions were interpreted reasonably per the plan's hints
  ("Designer restricted to assets/Figma links; Client reviewer scoped to one client with no billing
  visibility") and should be revisited once Assets (Step 0.7) and other modules land.
- Org owners now have a full Staff module (`/staff`) to invite, view, edit, and remove staff
  (Owner/Strategist/Designer/Viewer), with per-member activity logging — see
  `.junie/modules/staff.md`.

## Organization signup & platform management (Phase 15)

- Organizations can now be created without a seeder: the public `/register-organization` (agency)
  and `/start` (business/creator) signups and super-admin onboarding all run
  `App\Actions\Organizations\RegisterOrganizationAction`, which creates the `Organization` and its
  first `Role::Owner` together in one transaction.
- A self-serve owner receives exactly **one** email, the `OrganizationWelcomeMail`. Its single CTA
  is a signed `verification.verify` URL (48-hour validity, built by the action's `verificationUrl()`
  helper) that both verifies their email and drops them into the app. The action does **not** fire
  Laravel's `Registered` event (that would queue a second, separate verification email). Right after
  signup the owner is redirected into the app but bounced by the `verified` middleware to the
  "check your inbox" greeting (`auth/verify-email.tsx`) until they click the link. Super-admin
  onboarding still passes `setPasswordUrl` instead (that address is marked verified in the same
  transaction). See `auth.md`.
- `Role::SuperAdmin` users are org-less platform staff (`org_id = null`). The `org.member`
  middleware keeps every org-less account out of the org workspace (super admins are redirected to
  `/super-admin`).
- `organizations` gained `suspended_at`, `suspension_reason`, `suspended_by`; a suspended org's
  members are locked out by `EnsureOrganizationNotSuspended`. New `Organization` helpers: `owner()`,
  `posts()`, `invoices()`, `isSuspended()`, `active()`/`suspended()`/`excludingPreview()` scopes,
  `PREVIEW_SLUG`.
- Deleting an org (super admin only) deletes its users explicitly — `users.org_id` is
  `nullOnDelete()`, so they'd otherwise survive as org-less accounts.
- Full details: `.junie/modules/super-admin.md`.

## Changelog

- 2026-11-08: **WhatsApp number on the user (Step W-0.4).** `users.phone` +
  `users.phone_verified_at` (migration `2027_04_16_100100`), `phone_verified_at` cast,
  `routeNotificationForWhatsApp()`, and a `whatsAppVerified()` factory state. Verification and the
  profile field itself are W-1.1; these columns exist so the `whatsapp` channel has somewhere to
  route to. Tests: `tests/Feature/Notifications/WhatsAppChannelTest`.
- 2026-11-07: **Per-client staff access.** New `client_user` pivot and
  `organizations.restrict_staff_to_assigned_clients` (default false, so existing orgs are
  unchanged), `User::assignedClients()` / `accessibleClientIds()` (null = unrestricted),
  `ClientAccessScope` + a `Gate::before` denial, the client picker on `staff/edit`, and the
  settings toggle. `BelongsToOrganization::resolveRouteBinding()` now lifts both access scopes so
  the answer is a consistent 403 rather than 404 on some routes. Tests:
  `ClientAccessScopeTest` (17), including a drift guard that fails if any table with a `client_id`
  column is neither scoped nor exempt. Audit finding 9.
- 2026-10-25: Merged the two post-signup emails into one — `RegisterOrganizationAction` now stops
  firing `Registered` and instead passes a signed 48h `verifyUrl` to `OrganizationWelcomeMail`, so a
  self-serve owner gets a single welcome email with one verify-and-enter button. `verify-email.tsx`
  greeting reworded. Tests updated (`OrganizationRegistrationTest`, `BusinessRegistrationTest`).
- 2026-10-24: Phase M-8 — `organizations.is_design_partner`, `users.last_active_at` +
  `TrackUserActivity` middleware, and the `ProductFeedback` model. See `super-admin.md`.
- 2026-10-18: Campaigns enhancement C-9.4 — `organizations.campaign_features` (json, nullable): per-org overrides for templates, AI planner, wrap reports and campaign invoicing; read only via `App\Contracts\CampaignFeatures`.
- 2026-10-18: Campaigns enhancement C-3.4 — `RegisterOrganizationAction` seeds starter campaign templates (`SeedStarterCampaignTemplatesAction`) for every new organization.
- 2026-10-18: Step I-1.9 — tenant-isolation safety net (see "Tenant isolation" above): org global
  scope on 16 models, unscoped route binding/parent relations, authorize-before-validate fixes,
  tenant-scoped `exists:` rules, and the generated cross-tenant route test.
- 2026-10-18: Step I-1.7 — client and staff invitation links (`client-invite/{token}`, `staff-invite/{token}`) are throttled by the `public-token` limiter (20/min per IP + token, 60/min per IP). See `ai.md` for the full limiter table.
- 2026-10-18: Phase 17 — `OrganizationPolicy` gains `viewAnalytics` (same-org Owner/Strategist/Designer/Viewer), `viewRevenueAnalytics` (Owner/Strategist) and `viewAiCost` (Owner); `HandleInertiaRequests` shares `auth.can.viewAnalytics`. See `analytics.md`.
- 2026-10-17: Phase 15 — org signup/onboarding, `Role::SuperAdmin`, org suspension columns and
  helpers, `org.member` guard; see `super-admin.md`.
- 2026-09-08: Added `organizations` table, `org_id`/`role`/`client_id` on `users`, the `Role` enum,
  and `ClientPolicy` enforcing role-based access on clients; documented alongside Step 0.3's `Client`
  model.
- 2026-09-08: Added `OrganizationSeeder` (org + one user per role) and wired it into
  `DatabaseSeeder`.
