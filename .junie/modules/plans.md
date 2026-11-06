# Plans, entitlements & subscriptions

## Purpose
Everything that decides *what a workspace's plan lets it do* and *how it pays for it* — Phase CG-2
(`.junie/plans/business-competitor-gap.md`), delivering B-9.1–B-9.4 and B-12.2. Three layers:

1. **Plan definitions** (`config/plans.php`) — the single source of truth for prices and limits.
2. **Entitlements** — limit/gate enforcement in the Actions, with a friendly upgrade prompt.
3. **Subscriptions** — Laravel Cashier (Stripe) self-serve checkout + Customer Portal, behind a
   super-admin billing switch (global + per-org), reconciled onto `organizations.plan` by webhook.

This module is distinct from `billing.md`, which is **client invoices** (an agency billing its
clients). This is **our subscription revenue** (a workspace paying us) and the plan gates.

## Pricing (Step CG-0.1, confirmed 2026-10-28)
Positioning: "the AI marketing partner for one brand." Annual = two months free (monthly × 10).

| Plan (`key`) | Segment | GBP / USD monthly | Includes |
|---|---|---|---|
| `business_free` | business | £0 / $0 | 3 accounts, 30 scheduled posts/mo, 30 AI credits, 5 image credits, 1 seat, 30d analytics, 500 MB |
| `business_pro` | business | £15 / $19 | all networks, unlimited scheduling, 500 AI / 50 image credits, grid planner, inbox, 365d analytics, 5 GB |
| `creator_pro` | creator | £29 / $37 | Pro + bio page, store, public media kit, deals, email automation, 2 seats, 1000 AI / 100 image credits, 10 GB |
| `agency_standard` | agency | — | no limits, every gate on (today's product; not shown on the public pricing table) |

Open questions still carried in `business-competitor-gap.md` §5: exact free-plan selling fee, VAT
model, Stripe entity, handle namespace, and **whether the comment inbox is gated on Free** (§6 — the
proposed table puts it in Pro; see Known Gaps).

## Models / Tables
- `organizations` new columns (migration `2026_11_14_100100_add_billing_columns_to_organizations_table`):
  `plan` (string, nullable → the type default), the four Cashier customer columns (`stripe_id` idx,
  `pm_type`, `pm_last_four`, `trial_ends_at`), `billing_enabled` (nullable bool — the per-org override
  of the global switch; null = inherit), and the temporary AI credit grant
  (`bonus_ai_text_credits` int, `bonus_ai_credits_expires_at` nullable). `plan`/`billing_enabled`/
  the bonus columns are **deliberately not `#[Fillable]`** — set only by the Actions below, never
  mass-assigned. `Organization` uses Cashier's `Billable` trait (its subscriptions use an
  `organization_id` owner key; the existing `invoices()`/`taxRates()` relations win over Cashier's
  same-named trait helpers, which we don't use).
- `subscriptions` / `subscription_items` — Cashier's tables, written by hand (keyed on
  `organization_id`, not Cashier's default `user_id`) because the billable is the Organization.
- `platform_settings` (`key` unique, `value` json) — platform-wide super-admin settings. First key:
  `billing.enabled` (the global Stripe switch). Read/written only via `PlatformSettings`.
- `stripe_webhook_events` (`stripe_event_id` unique, `type`, `processed_at`) — webhook idempotency.
- `plan_audit_logs` (`org_id`, `actor_user_id`, `action`, `from_plan`, `to_plan`, `note`, `meta`) —
  the super-admin audit trail of plan overrides / credit grants / billing overrides. Model
  `PlanAuditLog` with action constants `plan_changed` / `credits_granted` / `billing_override`.
- `Organization` helpers: `effectivePlanKey()`, `currentPlan(): Plan`, `bonusAiTextCredits()` (0 when
  expired), `planAuditLogs()`.

## Actions / Services / Contracts
- `App\Enums\Feature` — the 8 numeric **limits** (`social_accounts`, `scheduled_posts_per_month`,
  `team_seats`, `ai_text_credits`, `ai_image_credits`, `brands`, `analytics_history_days`,
  `media_storage_mb`) and 7 boolean **gates** (`grid_planner`, `inbox`, `bio_page`, `store`,
  `public_media_kit`, `brand_deals`, `email_automation`). `isLimit()`/`isGate()`/`limits()`/`gates()`/
  `label()`.
- `App\Support\Plans\Plan` — read-only view over one `config/plans.php` entry: `limit()`, `allows()`,
  `price()`, `stripePriceId()`, `isFree()`/`isPaid()`, `trialDays()`, `highlights()`, `toArray()`.
- `App\Support\Plans\PlanCatalog` — the registry: `all()`, `find()`, `get()`, `resolve($key, $type)`
  (falls back to the type default), `defaultKeyFor($type)`, `public()`, `findByStripePriceId()`.
- `App\Contracts\Entitlements` (bound to `App\Services\Plans\ConfigEntitlements` in
  `AppServiceProvider`): `plan()`, `limit()`, `allows()`, `remaining()`, and `ensure($org, $feature,
  $current = 0)` — the enforcement seam that throws `App\Exceptions\Plans\PlanLimitReachedException`
  (a gate not granted, or a limit's cap reached). Agency plans are unlimited, so `ensure()` never
  fires for them.
- `App\Exceptions\Plans\PlanLimitReachedException` — renders itself as a **friendly upgrade prompt,
  never a 403**: an Inertia request is redirected back with a toast + an `upgrade_prompt` flash; a
  JSON request gets a 402. Carries the feature, current plan and a suggested upgrade plan.
- `App\Services\Platform\PlatformSettings` — cached key/value store for `platform_settings`;
  `billingEnabled()`/`setBillingEnabled()`.
- `App\Services\Plans\BillingGate` — `enabledForOrg($org)` = the per-org override if set, else the
  global switch; `globallyEnabled()`.
- `App\Actions\Billing\SyncSubscriptionAction` — reconciles `organizations.plan` from the org's
  Stripe subscription (maps `stripe_price` → plan via `PlanCatalog::findByStripePriceId`); no valid
  subscription falls back to the type default. Idempotent; a valid subscription on an unmapped price
  leaves the plan untouched (logged).
- `App\Listeners\SyncSubscriptionOnStripeWebhook` (on Cashier's `WebhookHandled`) — runs the sync for
  the affected org after Cashier updates its own tables, recording the event id in
  `stripe_webhook_events` for idempotency. Only `customer.subscription.*` / `invoice.*` events.
  Registered in `App\Providers\BillingServiceProvider`, which also calls
  `Cashier::useCustomerModel(Organization::class)`.
- Super-admin Actions (Step CG-2.6): `SetOrganizationPlanAction`, `GrantOrganizationCreditsAction`,
  `SetOrganizationBillingOverrideAction` — each wrapped in `DB::transaction()`, each writing a
  `PlanAuditLog`. The global switch is set directly via `PlatformSettings` (no per-org audit row).

## Enforcement points (Step CG-2.2)
- `CreateSocialAccountAction` → `Feature::SocialAccounts` (org-wide count).
- `CreatePostAction` → `Feature::ScheduledPostsPerMonth` (posts created this calendar month in the
  org's timezone — the MVP interpretation of "scheduled posts a month").
- `CreateStaffInvitationAction` → `Feature::TeamSeats` (members + outstanding invitations).
- `CreateGridPlanAction` → `Feature::GridPlanner` gate (enforced in the Action, so both the business
  and agency grid create paths are covered; agency plans always allow). `Business\GridController@index`
  surfaces the gate to the UI via an `entitled` prop (upgrade panel + hidden create button).
- `App\Services\Plans\PlanUsage` — the single, soft-delete-safe usage counter (`socialAccounts()`,
  `scheduledPostsThisMonth()` in the org timezone) shared by the enforcing Actions and the plan page,
  so "current usage" is counted the same everywhere and trashed rows never consume a slot.
- `AiUsageGuard::ensureWithinBudget()` → `Feature::AiTextCredits` (monthly text-request allowance +
  any bonus grant), alongside the existing M-7.1 token-budget ceiling. Throws the existing
  `AiBudgetExceededException` so the AI UI renders it unchanged.
- `ImageQuotaGuard` → the effective image quota is `ai_monthly_image_quota ?? plan ai_image_credits`
  (the plan sets the default; an explicit owner/super-admin quota still wins).

## Routes / Controllers
- `GET settings/plan` → `Settings\PlanController@edit` (`plan.edit`) — Owner-only; current plan, usage
  meters, upgrade cards, manage-billing. `POST settings/plan/checkout` (`plan.checkout`,
  `StartCheckoutRequest`) → Stripe Checkout via Cashier (`Inertia::location`); guarded so it never
  calls Stripe when billing is off or a price id is missing (friendly flash instead).
  `GET settings/plan/portal` (`plan.portal`) → Customer Portal. `GET settings/plan/success`
  (`plan.success`) → syncs the plan on return from Checkout.
- `POST /stripe/webhook` — Cashier's own `WebhookController` (auto-registered, signature-verified via
  `STRIPE_WEBHOOK_SECRET`), with our listener layered on top.
- Super admin: `super-admin.organizations.plan|credits|billing` (on `SuperAdmin\OrganizationController`)
  and `super-admin.settings.edit` / `super-admin.settings.billing`
  (`SuperAdmin\PlatformSettingController`) — the global Stripe switch + plan distribution.
  `GetOrganizationDetailAction` now returns a `billing` block (plan, override, credits, audit) +
  `planOptions`.
- Marketing (guest): `for-business`, `for-creators`, `pricing` → `MarketingController`. The pricing
  table is generated from `config/plans.php` (both currencies), so marketing figures never drift from
  enforcement.

## Business Rules
- `plan = null` resolves to the default for the workspace type (agency → `agency_standard` unlimited;
  business → `business_free`). So existing orgs are unaffected until explicitly moved.
- The global Stripe switch defaults **off** (billing ships dark). A per-org `billing_enabled` override
  wins either way, letting a super admin let one org through while the platform is still dark.
- Every plan must define every `Feature` (asserted by `PlanDefinitionsTest`) — no silent nulls.
- Cashier (subscriptions) and the future creator-store Connect integration share no tables, webhooks
  or keys (Principle 4): Cashier uses `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`.

## Tests
- `tests/Unit/Plans/PlanDefinitionsTest` — every plan defines every feature; agency unlimited; default
  keys resolve.
- `tests/Unit/Plans/ConfigEntitlementsTest` — one case per limit (at cap rejected, under allowed) and
  per gate (free denied, creator_pro allowed); agency never limited; upgrade suggestion is useful.
- `tests/Unit/Plans/EntitlementEnforcementTest` — social-account, monthly-post and team-seat caps are
  enforced in their Actions (allowed under, rejected at the cap).
- `tests/Unit/Services/AI/AiTextCreditAllowanceTest` — plan AI text credits enforced + bonus grant.
- `tests/Feature/Billing/SyncSubscriptionActionTest` + `StripeWebhookTest` — subscribe/upgrade/
  downgrade/cancel map onto `organizations.plan`; replayed events are idempotent.
- `tests/Feature/Settings/PlanPageTest` — Owner sees it; ClientReviewer/non-owners don't; checkout is
  gated when billing is off / price missing.
- `tests/Feature/SuperAdmin/PlanControlsTest` — plan override + credit grant + billing override + the
  global switch, each audited and super-admin-only.
- `tests/Feature/Marketing/PricingPagesTest` — the pricing table renders every public plan from config.
- `tests/Feature/Business/BusinessGridTest` — grid planner gated to Pro (free gets the upgrade prompt).

## Known Gaps / Open Questions
- **Inbox gating (open question §6):** the plan config gates `inbox` to Pro, but the shared `/inbox`
  surface is **not** yet hard-gated (it's a shipped CG-1.1 surface used by agency + business, and
  whether Free includes it is undecided — Buffer includes it everywhere). The `Entitlements` contract
  and tests already cover the `inbox` gate; surface enforcement is deferred until §6 is resolved.
- The gates `bio_page`, `store`, `public_media_kit`, `brand_deals`, `email_automation` are defined per
  plan but their surfaces don't exist yet (CG-3 onward); they'll call `Entitlements::ensure()` as they
  land.
- No real Stripe products/prices exist yet, so checkout/portal are reachable only once the
  `STRIPE_PRICE_*` env vars are set and the super-admin switch is on; tests cover the guarded "not
  available yet" paths, not a live Stripe round-trip.
- `scheduled_posts_per_month` is enforced at post *creation* per calendar month (MVP interpretation),
  not at the scheduled-transition step.
- The post and connected-account meters reset on the **org-timezone** month boundary, but the AI
  text-credit meter (`AiUsageGuard`) still resets on the **app-timezone** (UTC) boundary it has used
  since M-7.1 — so on the plan page the two can reset a few hours apart. Minor; left aligned with the
  existing AI metering rather than changing M-7.1 behaviour.
- Downgrade data-retention (over-limit accounts paused, not deleted — B-9.3) is not yet implemented;
  a downgrade simply lowers the plan.

## Changelog
- 2026-10-28: Phase CG-2 — plans, entitlements, Cashier subscriptions, super-admin plan controls +
  global/per-org Stripe switch, and public pricing/segment pages.
