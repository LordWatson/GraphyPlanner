# Plans, entitlements & subscriptions

## Purpose

Everything that decides _what a workspace's plan lets it do_ and _how it pays for it_ — Phase CG-2
(`.junie/plans/business-competitor-gap.md`), delivering B-9.1–B-9.4 and B-12.2. Three layers:

1. **Plan definitions** (`config/plans.php`) — the single source of truth for prices and limits.
2. **Entitlements** — limit/gate enforcement in the Actions, with a friendly upgrade prompt.
3. **Subscriptions** — Laravel Cashier (Stripe) self-serve checkout + Customer Portal, behind a
   super-admin billing switch (global + per-org), reconciled onto `organizations.plan` by webhook.

This module is distinct from `billing.md`, which is **client invoices** (an agency billing its
clients). This is **our subscription revenue** (a workspace paying us) and the plan gates.

## Pricing (Step CG-0.1, confirmed 2026-10-28)

Positioning: "the AI marketing partner for one brand." Annual = two months free (monthly × 10).

| Plan (`key`)    | Segment  | GBP / USD monthly | Includes                                                                                                      |
| --------------- | -------- | ----------------- | ------------------------------------------------------------------------------------------------------------- |
| `business_free` | business | £0 / $0           | 3 accounts, 30 scheduled posts/mo, 30 AI credits, 5 image credits, 1 seat, 30d analytics, 500 MB              |
| `business_pro`  | business | £15 / $19         | all networks, unlimited scheduling, 500 AI / 50 image credits, grid planner, inbox, 365d analytics, 5 GB      |
| `creator_pro`   | creator  | £29 / $37         | Pro + bio page, store, public media kit, deals, email automation, 2 seats, 1000 AI / 100 image credits, 10 GB |

### Agency pricing (Step GTM-0.1, decided 2026-11-07)

Agencies were unlimited and free until this step. They are now a tiered, public, paid segment —
**tiered on capacity, never on capability**: every agency plan grants every gate. Cloud Campaign
charges $199/$299 for less product, so Scale undercuts it.

| Plan (`key`)      | Segment | GBP / USD monthly | Brands    | Seats     | Accounts  |
| ----------------- | ------- | ----------------- | --------- | --------- | --------- |
| `agency_starter`  | agency  | £49 / $59         | 5         | 3         | 50        |
| `agency_standard` | agency  | £99 / $119        | 15        | 10        | 150       |
| `agency_scale`    | agency  | £199 / $239       | 50        | unlimited | 500       |
| `agency_legacy`   | agency  | —                 | unlimited | unlimited | unlimited |

`agency_legacy` is the pre-GTM-0.1 unlimited plan, kept **non-public and never sold**. The migration
`2026_11_20_100000_grandfather_existing_agency_plans` moved every agency org that existed at that
point onto it (those with `plan = null` or `agency_standard`, since both meant "unlimited" before),
with `organizations.plan_grandfathered_until` set **12 months** out. An agency with an explicit paid
plan key was not touched.

White label (GTM-3) and ads reporting (GTM-5) are intended to differentiate Standard and Scale, and
are deliberately **not** advertised on any tier until those phases build them.

### Creator Pro positioning (Step GTM-0.2, decided 2026-11-07)

Route (a): hold £29/$37 and change the story. The November 2026 review found Planoly bundles link in
bio, a 0%-fee store, a grid planner and auto-DMs at $14, so the storefront can no longer be the
headline. The tagline and highlights now lead on the brand deals tracker, publish-blocking ASA/FTC
disclosure, and a media kit built from real numbers — none of which Planoly, Stan or Beacons have.
The store and bio page are supporting bullets. No price change, no new features required.

The "How we compare" table floated in CG-2.7 was never built, so there was nothing to refresh;
GTM-7.3 covers comparison pages properly, with dated figures.

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
  (a gate not granted, or a limit's cap reached). Since Step GTM-0.1 `ensure()` **does** fire for an
  agency on a paid tier (brands, seats, accounts); only `agency_legacy` is unlimited.
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

- `plan = null` resolves to the default for the workspace type (business → `business_free`; agency →
  `agency_starter` since Step GTM-0.1, so an agency that has never paid is capped at the cheapest
  tier rather than unlimited). Existing agency orgs were pinned to `agency_legacy` by migration, so
  none of them changed.
- **The grandfather expires on purpose.** `plans:end-grandfather` (daily, 03:00) moves an
  `agency_legacy` org onto `agency_standard` once `plan_grandfathered_until` has passed, then clears
  the date so it can't fire twice. A `null` date means a permanent exemption — that is how one is
  recorded. The sweep never touches an org on a paid tier, or a non-agency org.
- An agency that hits a cap is offered the next **agency** tier; a solo workspace is never pointed at
  an agency plan, and an agency is never pointed at a solo one (`ConfigEntitlements::suggestUpgrade`).
- The global Stripe switch defaults **off** (billing ships dark). A per-org `billing_enabled` override
  wins either way, letting a super admin let one org through while the platform is still dark.
- Every plan must define every `Feature` (asserted by `PlanDefinitionsTest`) — no silent nulls.
- Cashier (subscriptions) and the future creator-store Connect integration share no tables, webhooks
  or keys (Principle 4): Cashier uses `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET`.

## Tests

- `tests/Unit/Plans/PlanDefinitionsTest` — every plan defines every feature; every agency tier grants
  every gate; `agency_legacy` alone is unlimited and non-public; the paid tiers' capacity and price
  rise together; default keys resolve.
- `tests/Unit/Plans/ConfigEntitlementsTest` — one case per limit (at cap rejected, under allowed) and
  per gate (free denied, creator_pro allowed); `agency_legacy` never limited; an agency with no plan
  gets Starter's caps; an agency at its cap is offered the next agency tier.
- `tests/Feature/Plans/AgencyPlanGrandfatherTest` — a grandfathered org keeps unlimited capacity
  until its date; the sweep moves it to `agency_standard` and is idempotent; paying, exempt
  (null-date) and non-agency orgs are left alone; the command runs the sweep.
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

- **AI credits are unlimited on every agency tier** (Step GTM-0.1 left this as it was). That was free
  when agencies didn't pay; now we sell an unlimited AI allowance for £49/mo, which is an open cost
  exposure, not a decision. Needs a per-tier `ai_text_credits` / `ai_image_credits` cap once there is
  real per-org usage data to set it from — `PlanUsage` and `AiUsageGuard` already meter it.
- **Nobody is told the grandfather ended.** `plans:end-grandfather` moves the org and logs a warning,
  but sends no email. Moving a customer onto tier caps without warning them is a comms decision, so
  it was deliberately left out rather than guessed at; it needs an email (and probably a reminder 30
  days before) before the first date actually lands.
- **No agency self-serve signup path was added.** The tiers are public and enforced, but an agency
  still arrives through the existing signup/super-admin route. Checkout works through Cashier once
  the `STRIPE_PRICE_AGENCY_*` env vars exist.
- ~~**Inbox gating**~~ — **resolved in Step GTM-1.3 (2026-11-07): the comment inbox is included on
  Free.** `business_free.features` now grants `Feature::Inbox`, so the config says what the code
  does (the shared `/inbox` surface was never hard-gated, so Free users already had it — the gate
  was the only thing claiming otherwise). Reasons: Buffer includes it on every plan; it is the
  surface that brings someone back daily, which is what converts; its cost is bounded by Free's
  3-account cap; and gating it now would take a shipped surface away from existing free workspaces.
  To reverse it, flip that one line and hard-gate the surface — the `Entitlements` gate and its
  tests already exist.
- **White labelling is on every agency tier, including Starter** (Step GTM-3.1). GTM-3.1's plan
  text said "true from `agency_standard` up", but GTM-0.1 had already decided the opposite and
  published it: every agency tier grants every gate, the tiers differ on capacity, and `/pricing`
  says so in as many words ("Tiers differ on how many brands and seats, never on features"). The
  implemented decision won. **That means white label sells at £49/mo, against Cloud Campaign's $199
  (standard) and $299 (advanced).** It is a pricing question, not a code one: tiering it is one
  line in `config/plans.php` plus `PlanDefinitionsTest::test_every_agency_plan_grants_every_gate`.
- **No per-plan email sending limit** (Step GTM-2). `email_automation` is a gate, not a meter, so a
  Creator Pro workspace can mail its whole list as often as it likes — the same open cost question
  as the agency AI credits above. See `audience.md`.
- Every gate now has a surface: `bio_page` (CG-3), `store` (CG-4), `public_media_kit` (B-8.4),
  `brand_deals` (CG-5), `email_automation` (GTM-2), `white_label` (GTM-3.1, agency tiers only),
  `custom_domain` (GTM-3.2, Creator Pro and up), `competitor_benchmarks` (GTM-4, Pro and up) and
  `ads_reporting` (GTM-5, agency tiers only).
- **A new limit defaults to _unlimited_.** `$limitDefaults` is all nulls, so a limit case added to
  `Feature` without a value on every plan is given away for free. `Feature::TrackedProfiles`
  (GTM-4.1) is set explicitly on all seven plans for that reason, including **0** on Free — which
  is a legitimate cap with no room below it, and is why
  `ConfigEntitlementsTest::test_every_limit_feature_is_enforced_at_its_cap_on_the_free_plan` now
  skips the under-the-cap half for a zero. Note that `email_automation` gates
  **sending**, not capturing — `/audience` renders and keeps collecting contacts without it,
  because refusing to record a buyer's address would lose the creator real data over a plan they
  can still upgrade to.
- No real Stripe products/prices exist yet, so checkout/portal are reachable only once the
  `STRIPE_PRICE_*` env vars are set and the super-admin switch is on; tests cover the guarded "not
  available yet" paths, not a live Stripe round-trip.
- `scheduled_posts_per_month` is enforced at post _creation_ per calendar month (MVP interpretation),
  not at the scheduled-transition step.
- The post and connected-account meters reset on the **org-timezone** month boundary, but the AI
  text-credit meter (`AiUsageGuard`) still resets on the **app-timezone** (UTC) boundary it has used
  since M-7.1 — so on the plan page the two can reset a few hours apart. Minor; left aligned with the
  existing AI metering rather than changing M-7.1 behaviour.
- Downgrade data-retention (over-limit accounts paused, not deleted — B-9.3) is not yet implemented;
  a downgrade simply lowers the plan.

## Changelog

- 2026-11-07: Phase GTM-5 added `Feature::AdsReporting` (agency tiers only; read-only paid-ads
  reporting — see `ads.md`). Phase GTM-7.1 added agency cap utilisation to the super-admin funnel,
  so the next change to these limits has evidence behind it.
- 2026-11-07: Phase GTM-4 added `Feature::CompetitorBenchmarks` (gate: Pro, Creator Pro and every
  agency tier) and `Feature::TrackedProfiles` (limit: 0 / 3 / 3 / 25 / 50 / 100 / unlimited).
  See `benchmarks.md`.
- 2026-11-07: Phase GTM-3 added two gates. `Feature::WhiteLabel` — every agency tier, no
  business or creator plan — decides whether an org's stored branding reaches a client surface
  (`settings.md`, `client-portal.md`). `Feature::CustomDomain` — Creator Pro and every agency
  tier — gates pointing a hostname at a bio page (`bio-pages.md`).
- 2026-11-07: Phase GTM-2 gave `Feature::EmailAutomation` its surface. It gates sending (the
  automated triggers with `allows()`, broadcasts with `ensure()`), never capture. See `audience.md`.
- 2026-11-07: Step GTM-1.3 — `Feature::Inbox` granted on `business_free` (CG open question §5.6
  answered: the inbox is on Free), with a "Comments and replies in one inbox" highlight. The
  every-gate-denied-on-Free test now names that one deliberate exception instead of asserting none.
- 2026-11-07: Phase GTM-0 — three public, paid agency tiers (£49/£99/£199) tiered on brands, seats
  and accounts; `agency_legacy` plus a 12-month grandfather and the `plans:end-grandfather` sweep
  that ends it; the agency default moved to `agency_starter`; upgrade suggestions kept within a
  segment; Creator Pro repositioned on deals and compliance; the pricing table grouped by segment.
- 2026-10-28: Phase CG-2 — plans, entitlements, Cashier subscriptions, super-admin plan controls +
  global/per-org Stripe switch, and public pricing/segment pages.
