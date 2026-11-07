# Settings

## Purpose
Org-level settings, Owner-only: the Upload-Post API key, the xAI (Grok) API key, the organization's
default timezone (Step 0.15), and the organization's default currency (preselected for new invoices and used to display org-level
amounts like retainers — no conversions, display only; each invoice carries its own currency). Complements `auth.md`, which covers the per-user
Profile/Security/Appearance settings pages.

## Models / Tables
- `Organization` (`organizations`): existing `default_timezone` column, a `default_currency` string(3)
  column (default `'USD'`, migration `2026_09_20_130642_add_default_currency_to_organizations_table`),
  plus nullable `upload_post_key`/`xai_key` text columns (migration
  `2026_09_16_183037_add_publish_adapter_keys_to_organizations_table`) and a nullable
  `upload_post_webhook_secret` text column (Step 1.5, migration
  `2026_09_20_181800_add_upload_post_webhook_secret_to_organizations_table` - the per-account signing
  secret `UploadPostWebhookController` verifies inbound webhooks against, since Upload-Post issues one
  secret per account rather than a single global one; see `publishing.md`). All three key columns are
  cast `encrypted` on the model and marked `#[Hidden]`, so they're encrypted at rest and never serialize
  into an Eloquent `toArray()`/`toJson()` call (and therefore never into an Inertia prop) even if the
  `Organization` model itself were ever passed to a view.

## Phase M-6.7 (I-12.1) — notification preferences
- `settings/notifications` (`NotificationPreferenceController`, `settings/notifications.tsx`, in the
  settings sidebar) — per-user `users.notification_preferences`: for each `NotificationCategory`,
  toggle in-app and daily-digest. See `notifications.md` › Phase M-6.7.

## Phase M-6.5 (Phase 9) — approval SLA
- `organizations.approval_sla_hours` (nullable, default 48; migration `2026_11_07_100100`) — hours a
  post may wait on review before it's "overdue". Owner-only, edited on the org settings page
  ("Approval SLA (hours)"), validated 1–720, cleared with a blank value (null = no SLA).
  `OrganizationSettingsUpdateRequest` + `UpdateOrganizationSettingsAction`. See `PostApprovalSlaService`
  (overdue computation) in `posts.md` / `home.md` and the reminder job in `notifications.md`.

## Phase M-9 — inbox reply SLA
- `organizations.inbox_sla_hours` (nullable; migration `2026_11_12_100100`) — hours a public comment may
  wait on a reply before it's "overdue" in the social inbox. Falls back to `config('inbox.sla.default_hours')`
  (24) when unset, so the inbox has a sensible SLA out of the box; a zero/negative effective value turns it
  off. Drives `App\Services\Inbox\InboxSlaService`, the daily `inbox:send-overdue-reminders` reminder and the
  overdue flag in the inbox. See `inbox.md`.

## Audit log (Owner only)
- `settings/audit-log` (`audit-log.index`) and `settings/audit-log/export`
  (`audit-log.export`, behind `throttle:exports` + `RequirePassword`, like the analytics and
  invoice exports) -> `App\Http\Controllers\Settings\AuditLogController`, page
  `settings/audit-log.tsx`, nav entry in `layouts/settings/layout.tsx` (owners only).
- `App\Services\Security\AuditTrail` normalises four **existing** tables into one stream:
  `staff_activity_logs`, `post_activity_logs`, `campaign_activity_logs` and `plan_audit_logs`
  (`AuditTrail::SOURCES`). It is read-only and writes nothing of its own, so it cannot become a
  second, disagreeing version of the truth.
- Deliberately **not** read, with reasons, in `AuditTrail::NOT_AUDITED`: `ai_usage_logs` (metering,
  not people's actions), `mcp_call_logs` (own console view), `grid_plan_activity_logs`
  (collaboration, not an access event).
- Why a union and not one `audit_logs` table: a single table would mean rewriting four working,
  tested write paths and migrating their history for a reporting view. The shortcut is confined to
  this one class and bounded at `AuditTrail::MAX_ROWS` (5,000 per source) so an export reads a
  window rather than an org's whole history. `AuditTrailTest` fails if a new `*_activity_logs` or
  `*_audit_logs` table appears that is neither read nor excluded.
- Filters (`AuditLogRequest`): `from`/`to` (defaulting to the last 30 days) and `actor`, which is
  `Rule::exists` **scoped to this org's members** so it cannot probe user ids elsewhere. The window
  is interpreted in the org's `default_timezone` and converted to UTC; timestamps are rendered back
  in that timezone on the page and in the CSV header.
- The page shows the most recent 200 entries and says so; the CSV carries the whole window.

## Data export (Owner only)

The portability half of GDPR, next to the delete half `PurgeOrganizationJob` already covers — a
product feature rather than a support ticket.

- `settings/data-export` (`data-export.index`), `POST` the same path (`data-export.store`,
  `throttle:6,1`) and `settings/data-export/{dataExport}/download` (`data-export.download`, behind
  `throttle:exports` + `RequirePassword`) -> `Settings\DataExportController`, page
  `settings/data-export.tsx`, nav entry for owners.
- `data_exports` (`DataExport`, `DataExportStatus`): one row per request — pending / ready / failed /
  expired, with `row_count`, `size_bytes`, `failure_reason` and `expires_at`. The row **outlives the
  file** on purpose: once purged it is the receipt that the export happened and is gone.
- `App\Services\Security\OrganizationDataExport` writes the archive: one CSV per set, plus a
  `README.txt` that says what is *not* in it. `BuildOrganizationDataExportJob` (reports queue,
  `ShouldBeUnique`, 900s) streams it to the private assets disk and notifies the requester
  (`DataExportReady`, database channel only — a link to an organisation's whole record does not go
  in an email).
- **What is exported** is declarative: `OrganizationDataExport::SETS` maps each table to how it
  finds the organisation — `org_id` directly, or through a `parent` set that already does. A child
  table is therefore one line, and the path is one a reader can check. An automatic foreign-key walk
  was tried first and rejected: following `created_by`-style back-references pulled in rows (even
  platform-wide ones like `super_admin_reports`) merely because they named one of the org's users.
- **What is not**, with a reason each, in `::EXCLUDED`: credentials and one-time tokens, derived
  metrics, AI suggestions, our own processing logs, the platform's own tables, and framework
  plumbing. Media **bytes** are not in the archive either (it would run to gigabytes) — `assets.csv`
  lists every file instead, and the README says so.
- **Credentials never travel**: `::REDACTED` blanks the named columns, and `::NOT_CREDENTIALS`
  records the columns whose names only look like one (`ai_monthly_token_budget`, a token *budget*).
- Three drift guards in `tests/Feature/Security/DataExportTest.php`: every table must be in `SETS`
  or `EXCLUDED`; every credential-looking column in an exported set must be redacted or reviewed;
  every set and redaction must name a table and column that exist. The second guard found a real
  leak while this was being written — `social_accounts.meta_access_token` was being exported,
  because the redaction list named `access_token`, a column that does not exist.
- Retention and the purge sweep: see `ops.md` -> "Data retention".
- `ext-zip` is now a declared requirement in `composer.json` (the archive is a ZIP).

## Organisation-wide two-factor
- `organizations.require_two_factor` (boolean, default false; migration `2027_03_19_100100`), the
  Owner switch on this page. Enforcement, the exemptions and the lockout guard are documented in
  `auth.md` ("Organisation-wide two-factor").

## Actions / Services / Contracts
- `App\Actions\Organizations\UpdateOrganizationSettingsAction`: `DB::transaction`-wrapped upsert of
  `default_timezone`, `default_currency`, plus the three keys. A blank/omitted key in the request
  leaves the currently stored key untouched (so the edit form never needs to redisplay — or
  round-trip — a secret just to "keep" it). Logs an `info` entry noting whether each key was
  updated, never the key values themselves.
- `App\Support\Options\CurrencyOptions`: curated `{value, label}` list of ISO 4217 currency codes,
  mirroring `LanguageOptions`, used to populate the `default_currency` dropdown and the per-invoice
  currency selects (`codes()` backs the invoice requests' `Rule::in` validation; see `billing.md`).

## Phase M-2 additions

- Saving a new Upload-Post key registers the publish webhook with the vendor and stores its secret
  automatically (`RegisterUploadPostWebhookAction`, M-2.5); a failure keeps the key and the old
  secret (the poll fallback still confirms deliveries).

## Phase M-1 additions (Step M-1.2)
- `GET`/`PUT settings/organization` are behind `password.confirm` (the page holds the Upload-Post
  key, webhook secret and xAI key): the Owner re-enters their password at most every
  `auth.password_timeout` (3 h). Tests use `TestCase::confirmPassword()`.

## Routes / Controllers
- `GET /settings/organization` → `App\Http\Controllers\Settings\OrganizationController@edit`
  (`organization.edit`) — renders `resources/js/pages/settings/organization.tsx` with
  `default_timezone`, `default_currency`, three booleans (`has_upload_post_key`,
  `has_upload_post_webhook_secret`, `has_xai_key`), and a `currencies` options list; the raw key
  values are never included in the props.
- `PUT /settings/organization` → `@update` (`organization.update`) — validates via
  `OrganizationSettingsUpdateRequest`, calls the Action, flashes a toast, redirects back to
  `organization.edit`.
- Both routes sit in the existing `auth`+`verified` group in `routes/settings.php`, alongside
  Profile/Security/Appearance.
- **Settings → AI connections (MCP-6.1, Owner-only).** `GET /settings/ai-connections`
  (`mcp-connections.edit`, behind `RequirePassword` — it issues secrets) →
  `App\Http\Controllers\Settings\McpConnectionController@edit` renders
  `resources/js/pages/settings/ai-connections.tsx` with the org's MCP tokens (safe shape, never the
  hash), the scope catalogue, usage/audit (`GetMcpUsageAction`), the per-org master switch
  (`organizations.mcp_enabled`) and the connection URL. `POST …/tokens` (`store`) mints a token via
  `CreateMcpTokenAction` (plain token flashed once), `DELETE …/tokens/{mcpToken}` (`destroy`) revokes
  via `RevokeMcpTokenAction` (org-guarded binding), `PUT …` (`update`) toggles the switch. Owner-only
  via `OrganizationPolicy::update`. Full detail lives in `.junie/modules/mcp.md`.

## Step GTM-3.1 — white-label branding

Six nullable columns on `organizations` (`2026_12_12_100100_add_white_label_branding_to_organizations`):
`brand_logo_disk`, `brand_logo_path`, `brand_accent` (`#RRGGBB`, validated), `brand_sender_name`,
`brand_reply_to`, `brand_footer`. Null everywhere means Alima's own chrome, which is what every
existing org already has.

- **Resolved in one place**: `App\Services\Branding\BrandingResolver` →
  `App\Support\Branding\Branding`. The client portal, the monthly report PDF and the client-facing
  mailables all read it, so no two surfaces can disagree about who the client is dealing with.
- **Saved whatever the plan says, resolved only with `Feature::WhiteLabel`.** The settings form
  stores what the agency types on any plan; the resolver returns `Branding::alima()` without the
  gate. So a downgrade takes the branding off every client surface at once, with no migration and
  nothing to clean up, and an upgrade doesn't ask them to type it again.
- **The logo** goes through `AssetStorage` (private disk, signed URLs) on its own endpoint
  (`POST`/`DELETE settings/organization/logo`), so the settings `PUT` stays a plain JSON submit.
  `UpdateBrandLogoAction` deletes the replaced file **after** the new columns commit.
- Both logo routes sit behind `RequirePassword` like the rest of this page.

## Business Rules
- Owner-only, scoped to the acting user's own org: enforced by `App\Policies\OrganizationPolicy::update`
  (`$user->org_id === $organization->id && $user->isOwner()`), checked both in
  `OrganizationSettingsUpdateRequest::authorize()` and via `$this->authorize()` in the controller's
  `edit()` action. Non-owners get a 403; there is no way to target another org's settings since the
  organization is always resolved from `$request->user()->organization`, never a route parameter.
- `default_timezone` is required and validated as a real IANA identifier (`timezone:all`, mirroring
  `SocialAccount`'s timezone validation). `default_currency` is required, a 3-letter ISO 4217 code.
- The settings sidebar (`resources/js/layouts/settings/layout.tsx`) only shows the "Organization" nav
  entry when `auth.user.role === 'owner'`.
- `default_currency` is shared on every Inertia response as the `currency` prop (see
  `HandleInertiaRequests::share()`) so any page can format an amount via
  `resources/js/lib/currency.ts`'s `formatCurrency()` without a dedicated round-trip. No currency
  conversion is performed anywhere — new invoices default to the organization's
  `default_currency` but can be billed in another currency (see `billing.md`), and the shared prop only controls display formatting for
  amounts (e.g. `Client::retainer_amount`) that don't carry their own currency.

## Tests
- `tests/Feature/Settings/OrganizationSettingsControllerTest.php`: guest redirect; Owner can view/update;
  non-Owner is forbidden on both routes; blank keys don't overwrite existing stored keys; a non-owner
  can't reach another org's settings; and an automated assertion (`assertDontSee`) that the rendered
  page's raw HTML/JSON never contains a stored secret key value — the automated check called for by the
  plan's "Done when" criterion.

## Known Gaps / Open Questions
- **No per-agency sending domain** (Step GTM-3.1). A white-label email carries the agency's display
  name and reply-to, but the envelope from-address stays our own verified domain, because SPF and
  DKIM are signed for it and spoofing the from-address would land their mail in spam. A real
  `mail.youragency.com` needs per-domain DKIM records and a sending-identity record per org.
- **The logo is not in email.** The portal and the report PDF show it; the emails show the accent
  and the agency's name instead. Most clients block remote images by default, and a signed URL
  lapses, so a logo there would be unreliable either way.
- `upload_post_key` / `upload_post_webhook_secret` are consumed by the Upload-Post adapter, webhook
  verification and webhook auto-registration (see `publishing.md`). `xai_key` is stored but still has
  no provider behind it (AI text runs on DeepSeek from app config). (Corrected 2026-10-19, I-16.5.)
- Resolved 2026-10-18 (Step I-1.6): the last four characters of each stored key are shown in its
  placeholder.

## Changelog
- 2026-11-07: Added the Owner-only **Data export** (`data_exports`, `OrganizationDataExport`,
  `BuildOrganizationDataExportJob`, `exports:purge-expired`, `settings/data-export.tsx`) — see the
  section above and `ops.md` for retention.
- 2026-11-07: Added the Owner-only **Audit log** page and CSV export (`AuditTrail`,
  `AuditLogController`, `AuditLogRequest`, `settings/audit-log.tsx`) and the organisation-wide
  **two-factor** switch (`organizations.require_two_factor`; enforcement in `auth.md`). Also fixed
  the `restrict_staff_to_assigned_clients` checkbox on `settings/organization.tsx`: it was an
  uncontrolled `Checkbox` with no hidden field, so an unchecked box submitted nothing and the
  setting could be turned on but never off. Both toggles now follow the house pattern (controlled
  `Checkbox` + hidden `1`/`0` input) used on `settings/workspace.tsx`. Tests in
  `tests/Feature/Security/AuditTrailTest.php` and
  `tests/Feature/Security/TwoFactorEnforcementTest.php`.
- 2026-10-24: UI — `SettingsLayout` (which wraps every settings sub-page) now opens with the Ink `ChapterHero` in place of `PageHeader`, matching the other chapter pages. No aside (settings has no stable count); the section nav and forms below are unchanged. See `frontend.md`.
- 2026-10-24: Step M-4.1 — the Organization settings page gained the agency's VAT/TRN number +
  label (`tax_registration_number`/`tax_registration_label`, drive the "Tax invoice" heading) and a
  **Tax rates** manager (add / edit / archive, Owner-only via `TaxRatePolicy`;
  `tax-rates.store`/`.update`/`.archive` → `Settings\TaxRateController`). See `billing.md`.
- 2026-10-19: Step M-0.3 / I-16.5 — documentation drift: Known gaps now say which keys are consumed.
- 2026-10-20: Phase M-2 — webhook auto-registration on key save.
- 2026-10-18: Step I-1.6 — the organization page shows the last four characters of each stored key
  (`upload_post_key_last4`, `upload_post_webhook_secret_last4`, `xai_key_last4`; only for values
  longer than 8 characters) and has a "Test connection" button for Upload-Post
  (`POST settings/organization/test-upload-post`, Owner, throttled 6/min,
  `TestUploadPostConnectionAction` → new `PublishAdapter::verifyCredentials()`). No AI-key test: the
  only per-org AI key (`xai_key`) has no provider behind it yet, and DeepSeek's key is app config.
- 2026-10-16: Brand-book overhaul (UI only): the settings nav is Contents-style (mono number +
  title) with an Ink pill for the active section; the header eyebrow no longer repeats the chapter
  ("Account & studio"); `Heading variant="small"` is now a ruled spec header (ink hairline over a
  17px title), so the organization page drops its own section borders.
- 2026-10-16: Organization settings gained an "Invoicing" section: `billing_details` and `payment_instructions` (text, printed on every generated invoice PDF and invoice email) and `invoice_payment_terms_days` (0-365, default 14; new invoices without a due date are due this many days after issue). Persisted by `UpdateOrganizationSettingsAction` only when submitted; validated in `OrganizationSettingsUpdateRequest`. See `billing.md`.
- 2026-09-20: Step 1.5 (publishing module) — added `upload_post_webhook_secret` (encrypted,
  hidden) next to `upload_post_key`, plus its request/action/controller/form field, since
  Upload-Post issues one webhook signing secret per account rather than a single global one. See
  `publishing.md` for how `UploadPostWebhookController` resolves and verifies it.
- 2026-09-20: Added an org-level `default_currency` (ISO 4217, default `USD`) setting —
  `CurrencyOptions` support class, request/action/controller/frontend wiring, and a `currency` shared
  Inertia prop (`HandleInertiaRequests::share()`) plus `resources/js/lib/currency.ts`'s
  `formatCurrency()` so every currency field in the UI (invoices, retainer amounts, dashboard totals)
  displays in the organization's currency. `CreateInvoiceAction`/`UpdateInvoiceAction` now force every
  invoice's `currency` to the organization's `default_currency` (no per-invoice override, no
  conversions) — see `billing.md`.
- 2026-09-16: Initial implementation of Step 0.15 — `upload_post_key`/`xai_key` columns (encrypted +
  hidden), `OrganizationPolicy`, `UpdateOrganizationSettingsAction`, `OrganizationController`,
  `organization.edit`/`organization.update` routes, and the `settings/organization` Inertia page.
- 2026-10-17: Step 16.11 Settings → AI usage (Owner only): `GET/PUT settings/ai-usage` → `Settings\AiUsageController` (`GetAiImageUsageAction`, `UpdateAiImageQuotaAction`, `AiUsageUpdateRequest`), `organizations.ai_monthly_image_quota` (null = unlimited), page `settings/ai-usage.tsx` (StatStrip: Images, Finals, Est. cost + editable limit), nav item for owners. Tests in `tests/Feature/AI/ImageStudioHousekeepingTest.php`.
- 2026-10-20: Step M-1.2: organization settings require a recent password confirmation.
- 2026-11-10: Step M-7.1 extends Settings → AI usage. `AiUsageController::edit` adds `textUsage`
  (monthly token budget summary via `AiUsageGuard`), `performance` (acceptance rate via
  `AiPerformanceService`), `settings.{ai_enabled, ai_monthly_token_budget}` and `aiConfigured`.
  New `updateSettings` (`PUT settings/ai-settings` → `ai-settings.update`, Owner-only) saves the
  per-org AI enable toggle + monthly token budget via `UpdateAiSettingsAction`
  (`AiSettingsUpdateRequest`). `settings/ai-usage.tsx` now shows the AI enable toggle, a token-budget
  field, and a text-usage/acceptance `StatStrip` above the existing image quota. See `ai.md` ›
  "AI control plane". Tests in `tests/Feature/AI/AiControlPlaneTest.php`.
- 2026-10-28: Phase CG-2.5 — a new Owner-only "Plan & billing" settings page (`Settings\PlanController`,
  route `plan.edit`, nav entry in `settings/layout.tsx`): current plan, this-month usage meters
  (accounts, posts, AI credits), monthly/annual upgrade cards (Stripe Checkout via Cashier) and a
  "Manage billing" link (Customer Portal). All Stripe calls are guarded, so the page renders fine
  while billing is off / prices are unset. Full detail in `plans.md`.
