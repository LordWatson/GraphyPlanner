# Alima Planner — MVP Plan (sellable to other agencies)

_v2, 19 Oct 2026. Reconciled against PRD-001 (market research, competitor gaps and pricing, v1) and
against the code after the Campaigns Enhancement plan shipped. The v1 → v2 changes are listed at the
end._

This plan turns what Graphy already does into a product we can sell to other agencies. It closes the
gaps that lose deals, and it sharpens the "client to cash" position that no competitor matches. It
doesn't add a new product area. Most of the work is already written up in the other plans; this file
chooses **which** steps make the MVP and **in what order**.

It sits alongside:

- `.junie/plans/graphy-development-steps.md`: what to build (Phases 0–17)
- `.junie/plans/improvements.md`: what to fix and deepen (`I-` steps)
- `.junie/plans/campaigns-enhancement.md`: Campaigns v2 (`C-` steps, **all done**; this plan wires into it)
- `.junie/plans/business-workspaces.md`: business and creator workspaces (`B-` steps, **not** in this MVP)

How to use this file (same rules as the other plans):

- Work through the phases **in order**. Steps inside a phase are ordered too, unless a step says it
  can run in parallel.
- Each step is one unit of work for the coding agent: a goal, the files and models it touches, and a
  **Done when**.
- A step that says "Refer to Step X in `<plan>.md`" means: build that step exactly as written there,
  plus any **MVP notes** listed here. When it's finished, mark it `✅ Done` **in both files**, with an
  "Implemented via …" note in the source plan.
- A step marked **[Conditional]** only runs if the decision it names (usually in M-0.1 or M-8.3) says
  so. Otherwise skip it, and record the skip in the Decisions section.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), one-job Actions, interfaces in `App\Contracts\*` for anything swappable,
  `DB::transaction()` around multi-write logic, logging at boundaries with ids and never secrets,
  PHPUnit coverage (happy path, rejections, and a regression test for every bug), and PHPStan level 7
  with no new ignores or baseline entries.
- Every step updates the matching `.junie/modules/<module>.md` in the same change (use
  `sync-module-docs`). Frontend steps use `graphy-frontend-page` and follow
  `.junie/brand-guidelines.md`.
- Run `graphy-checks` at the end of every step, and the full `composer ci:check` at the end of every
  phase.
- Step ids use the prefix `M-` (e.g. `Step M-2.3`) so they never collide with `2.3`, `I-2.3`, `C-2.3`
  or `B-2.3`.

---

## Why this MVP

### Competitive position (PRD-001, checked 19 Oct 2026)

- **The nearest competitor is Wiro** (wiro.ae): a Dubai-built agency OS with projects, clients,
  finance, a scheduler and client portals, priced from $99. It's waitlist-only, so every claim it
  makes is unverified, and it's offering 30% off to its first 50 agencies.
- **Planable** is the main social collaboration rival. It charges per workspace (one per client)
  with post caps, has paid analytics and inbox add-ons, and has no CRM or invoicing.
- **Per-seat tools** (Hootsuite, Sprout, Agorapulse) look expensive at agency size. Hootsuite only
  offers approvals on its $399-per-user plan.
- **Agency ops tools** (Productive, Teamwork, Scoro, Accelo) bill and track time, but don't publish.

### Where we already win (polish these, don't rebuild them)

1. **Client to cash in one place:** CRM, retainers, client health with plain-English reasons,
   approvals, the client portal, publishing, **Campaigns v2** (briefs signed off by the client,
   phases, the AI planner, board, readiness, KPI results, AI wrap reports, invoicing from campaigns,
   profitability) and invoicing with branded PDFs.
2. **Free unlimited client reviewers** and a real client portal, compared with guest links or paid seats.
3. **AI that suggests and never publishes,** grounded in Brand Brain and guardrails. Rivals are moving
   to autonomous agents, so this is a trust message.
4. **Built for UK and UAE together:** dual-timezone targets, per-invoice currency (GBP/AED),
   right-to-left Arabic captions. ⚠️ The Arabic **interface** is *not* built yet (see M-0.1 and
   M-6.8).
5. **Operational analytics** (approval turnaround, workload, revenue, AI cost) that social tools
   don't have.

### Gaps that block a sale (what this plan closes)

| # | Gap (PRD-001 rank) | Impact | Phase |
|---|---|---|---|
| 1 | Engagement analytics in client and campaign reports | 5 | M-3 |
| 1L | Self-serve subscriptions, trials and plan limits (public-launch blocker) | 5 | M-10 |
| 2 | Networks: X, YouTube Shorts, Threads, Pinterest, Google Business Profile (+ IG Stories) | 4 | M-2 |
| 3 | VAT/tax lines and VAT/TRN numbers on invoices | 4 | M-4 |
| 4 | Client self-connect link for social accounts | 3 | M-2 |
| 5 | Canva and Google Drive/Dropbox import | 3 | M-5 |
| 6 | Bulk scheduling / CSV import | 3 | M-5 |
| 7 | Approval reminders, SLAs, any/all/sequential approvers | 3 | M-6 |
| 8 | Social inbox with AI draft replies | 3 | M-9 [Conditional] |
| 9 | Scheduled reports sent to clients | 3 | M-3 |
| 10 | Mark-paid/confirm-payment handshake (MVP); online card payment and accounting sync (after launch) | 3 | M-4 / M-12 |
| 11 | Time tracking against retainers, with client profitability | 3 | M-13 |
| 12 | White-label (logo first) | 3 | M-10 |

**Not in the MVP:** social listening, competitor benchmarking, "universal content", native mobile
apps, business/creator workspaces, ads, UAE e-invoicing (2027 deadlines; revisit when an Accredited
Service Provider is chosen), and anything in spec §13.

---

## Phase M-0 — Decisions & groundwork ✅ Done

Goal: lock scope and pricing, confirm what the vendors can actually do, and get the docs accurate
before any code depends on them.

### Step M-0.1 — Record MVP scope, pricing and open decisions ✅ Done
- **Implemented via (19 Oct 2026):** Decisions section below. Pricing, gating and the Group tier are pending approval, and Arabic is "no" (M-6.8 skipped).
- Write the following into this file's **Decisions** section:
  - **Scope:** the in-scope and out-of-scope lists above.
  - **Pricing (from PRD-001 §5; a recommendation until Alex and Rimma approve it):** per-agency
    subscription, banded by **active client brands**, with seats included, **unlimited free client
    reviewers** that never count as seats, and social profiles not metered except a fair-use limit
    of about 10 per brand. **Posts are never limited.** Tiers: Studio £99 / AED 449 (6 brands,
    5 seats), Agency £199 / AED 899 (15, 12), Agency Plus £399 / AED 1,799 (40, 25), Group from
    £799 / AED 3,699. Annual billing is 10 months for 12. 14-day trial with no card. No free plan.
    All prices ex VAT.
  - **Gating rule:** never gate the client-to-cash core (approvals, portal, invoicing, client
    health, publishing). Gate the time-savers: AI campaign planner, wrap reports, campaign templates
    and comparison, saved and scheduled reports, invoices from campaigns, and the AI allowance.
    White-label is included in Agency Plus and a £49 add-on elsewhere, and is **only sold once
    built**.
  - **Group tier:** not listed until a real prospect needs it. One customer owning several agencies
    needs multi-org membership (I-12.3), which **isn't built**: users have a single `org_id`.
  - **Arabic:** decide whether UAE design partners need an Arabic **client experience** (portal,
    review link, client emails and PDFs). If yes, M-6.8 runs. An Arabic staff interface is after
    launch. Right-to-left captions are already built.
  - **Inbox go/no-go:** this is decided in M-8.3 from design-partner feedback, not now.
- **Done when**: the Decisions section holds each item above, with who approved it and the date (or
  "pending").

### Step M-0.2 — Vendor capability audit ✅ Done
- **Implemented via (19 Oct 2026):** `publishing.md` › Capabilities (publish, formats), Analytics & comments (per-platform source for M-2.8, M-3 and M-9), Meta app scopes, and vendor cost. Audited from Upload-Post's docs and pricing page; a live sandbox check is still due (see Decisions).
- Confirm, against Upload-Post's current docs and a sandbox account, and record in
  `.junie/modules/publishing.md` (Known gaps / Capabilities):
  - which of YouTube (Shorts), **Google Business Profile** (unverified in PRD-001, and important for
    Dubai F&B and hospitality), Threads, Pinterest, X and Bluesky we can publish to, and the fields
    each one takes;
  - first-comment, Stories and carousel support per platform;
  - cancelling and editing a scheduled post;
  - whether the vendor connect URL can be **handed to a third party** (needed by M-2.8);
  - post and account **analytics** endpoints (needed by M-3);
  - **comment reading and replying** (needed by M-9);
  - Upload-Post's price per profile at our expected volume, because it affects margin per tier.
- For every capability Upload-Post doesn't have, record the fallback: either the Meta Graph API we
  already integrate (Facebook/Instagram comments and insights), or "deferred". Only reach for a
  Phase 4 second adapter if a gap blocks an MVP phase's exit criteria. For Google Business Profile,
  B-11.5 in `business-workspaces.md` describes a direct integration if the vendor can't do it.
- Check the Meta app's permissions and review status for any new scopes (comments, insights) and
  note the lead time, because app review can block M-3 and M-9.
- **Done when**: `publishing.md` has a capability table per platform, and each MVP phase that depends
  on a vendor feature names its source (Upload-Post, Meta Graph, or deferred).

### Step M-0.3 — Documentation drift ✅ Done
- **Implemented via (19 Oct 2026):** I-16.5 fixes, README statuses, and `tests/Unit/ModuleDocsTest.php` (README ↔ files check, runs in `composer test` / CI).
- Refer to **Step I-16.5** in `improvements.md`.
- **MVP note:** also fix `.junie/modules/README.md`:
  - Phase 6 and Phase 17 → Done;
  - `client-portal.md` → Steps 6.2–6.6 done;
  - `campaigns.md` → enhancement Done (C-0.1 to C-9.4);
  - remove the `review-portal.md` and `inbox.md` rows until those files exist.

### Phase M-0 exit criteria
- Scope, pricing shape and vendor capabilities are written down, and the module README matches the code.

---

## Phase M-1 — Production foundations ✅ Done (except M-1.3, deferred)

Goal: we can host other agencies' data safely, see failures, and refactor the editor without fear.
(Waves 1 and 2 of `improvements.md` are done, so this phase covers what's left before strangers use the app.)

### Step M-1.1 — Observability ✅ Done
- **Implemented via (20 Oct 2026):** correlation ids in `Context` (request → job → webhook, `post_targets.publish_correlation_id`), `ErrorTracker` (Sentry, secrets scrubbed), `OpsAlerter` on failed publishing jobs and bad webhook signatures, JSON log channel, Pulse; `ObservabilityTest`. See `ops.md`.
- Refer to **Step I-16.1** in `improvements.md`.

### Step M-1.2 — Security hardening ✅ Done
- **Implemented via (20 Oct 2026):** `SecurityHeaders` (nonce CSP, HSTS, framing, nosniff, referrer/permissions), secure session cookie in production, `composer`/`npm audit` in CI, password confirmation for keys/exports/purges, `SafeUpload` + `VirusScanner` (ClamAV); `SecurityHardeningTest`. See `ops.md`, `assets.md`.
- Refer to **Step I-16.2** in `improvements.md`.

### Step M-1.3 — S3 asset storage and direct uploads ⏸ Deferred
- **Deferred (20 Oct 2026, Alex):** the S3 bucket isn't set up yet, so this step waits; uploads stay on the private local disk with signed URLs. Picked up before M-8.1.
- Finish the S3 `AssetStorage` implementation (the package is still pending per `assets.md`), bound
  behind the existing contract, private bucket, signed URLs only (keeping the I-1.6 behaviour).
- Then refer to **Step I-7.1** in `improvements.md` (presigned multipart uploads).
- **Done when**: production config uses S3, local and tests use a faked disk, and I-7.1's Done when is met.

### Step M-1.4 — Frontend primitives ✅ Done
- **Implemented via (20 Oct 2026):** shadcn `table`/`popover`/`command`/`calendar`/`progress`, `DataTable` on every list page (clients, posts, invoices, staff, super-admin orgs, reports), `DatePicker`/`Combobox`, `lib/http.ts` with a CI grep guard (`scripts/frontend-guards.mjs`). See `frontend.md`.
- Refer to **Step I-14.2** in `improvements.md`. `DataTable` is needed by M-5.1.

### Step M-1.5 — Frontend test harness ✅ Done
- **Implemented via (20 Oct 2026):** Vitest + RTL (`npm test`), Playwright `signup-to-scheduled.spec.ts` (`npm run test:e2e`), both in CI. See `frontend.md`.
- Refer to **Step I-14.6** in `improvements.md`.
- **MVP note:** set up Vitest, RTL and Playwright in CI now, with the *sign up → first post
  scheduled* journey. The other journeys are added in the phases that change them (M-4, M-6, M-10,
  M-11).

### Step M-1.6 — Editor decomposition ✅ Done
- **Implemented via (20 Oct 2026):** editor split into `components/post-editor/editor/*` (no editor file over 300 lines, CI-guarded), shared `DateTimePicker` everywhere a date/time is chosen (account clock + viewer's time, explicit "Same moment"), ⌘S / ⌘↵ / ⌘K / `?` shortcuts, Undo toasts. See `posts.md`.
- Refer to **Step I-4.6** in `improvements.md`. Do this before M-2, because M-2 adds per-platform tabs and
  format controls to the editor.

### Phase M-1 exit criteria
- Errors and failed publish jobs alert with post and correlation ids, and uploads go straight to
  private S3.
- The editor is split into feature components, and CI runs Vitest and one Playwright journey.

---

## Phase M-2 — Publishing parity

Goal: no agency is turned away because of a network or format, and clients can connect their own
accounts without sharing a password.

### Step M-2.1 — Platform capability registry ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `config/platforms.php` + `PlatformCapabilities`, shared as the `platforms` prop; grep test in `PlatformCapabilitiesTest`.
- Refer to **Step I-3.3** in `improvements.md`. Every later step in this phase reads from it.

### Step M-2.2 — Per-platform captions, first comment, alt text ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via target overrides + master link/first comment, `CaptionComposer`, per-asset alt text; campaign defaults fill per-channel links/hashtags (`ApplyCampaignDefaultsAction::applyToChannels()`). Acceptance test `ChannelOverridesTest`.
- Refer to **Step I-3.1** in `improvements.md`.
- **MVP note:** campaign defaults (C-4.2) must fill the new per-target fields as well as the master
  caption (dependency table in `campaigns-enhancement.md` §1.6).

### Step M-2.3 — Explicit formats: feed, reel, story, carousel, short ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `post_targets.format`, `post_assets.sort_order`, cover asset + offset, registry formats; `PostFormatsTest`.
- Refer to **Step I-3.2** in `improvements.md`.

### Step M-2.4 — Media validation and processing ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `ProbeAssetJob` + `MediaProber` (ffprobe), registry media checks with reasons, one-click fixes, `thumbnail_url` on lists/grids; `PostFormatsTest`, `ProbeAssetJobTest`.
- Refer to **Step I-3.5** in `improvements.md`.

### Step M-2.5 — Cancel and edit after scheduling ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `PublishAdapter::cancel(PostTarget)`, `VendorScheduleService` recall/resubmit (unschedule, edit, reschedule, campaign pause/shift), webhook auto-registration; `VendorHeldPostsTest`.
- Refer to **Step I-3.6** in `improvements.md`. Campaign pause, resume and shift (C-6.1, C-6.2) must
  use the new cancel and re-submit path for vendor-held posts (regression test).

### Step M-2.6 — More networks ✅ Done
- **Implemented via (20 Oct 2026):** Implemented: YouTube Shorts, Google Business Profile, Threads, Pinterest, X, each with a rollout flag, registry entry, preview layout and faked-vendor test (`NewNetworksPublishTest`). Bluesky deferred (see Decisions).
- Refer to **Step I-3.4** in `improvements.md`.
- **MVP note:** the MVP needs YouTube Shorts, Google Business Profile, Threads, Pinterest and X, **in
  that order**. Google Business Profile moves up from I-3.4's order because of the Dubai F&B and
  hospitality segment. Bluesky is a cheap extra if M-0.2 confirms support. One platform per
  sub-step, each with its own rollout flag, capability entry, `SocialPostPreview` component and
  faked-vendor publish test. Skip any network M-0.2 marked "deferred", and record it.

### Step M-2.7 — Token lifecycle ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `CheckTokenExpiryAction` + `MetaTokenService` refresh, 7d/1d warnings, Home row, Amber health, health check recorded on the account; `SocialAccountTokenLifecycleTest`.
- Refer to **Step I-3.9** in `improvements.md`.

### Step M-2.8 — Client self-connect link (new) ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `connect_invites`, `CreateConnectInviteAction`, `/connect/{token}`, portal panel, `ClientConnectInviteMail`; `ConnectInviteTest`.
- Today only Owner/Strategist can start a connection (`SocialAccountController::connect` returns the
  vendor URL to a staff popup), so agencies end up asking clients for passwords or screen-shares.
- `App\Actions\SocialAccounts\CreateConnectInviteAction`: an Owner/Strategist creates a link for one
  client, optionally limited to specific account slots or platforms. Store `connect_invites` (`org_id`,
  `client_id`, hashed token, `allowed_social_account_ids` or `platforms` json, `expires_at` (default
  7 days), `revoked_at`, `created_by`, `used_at`). The raw token is shown and emailed once and never
  stored or logged (the same rules as review tokens).
- Public `GET /connect/{token}`: a branded page listing the client's account slots, each with a
  "Connect" button that runs the existing vendor connect flow (`PublishAdapter::connectAccount()`,
  or the Meta connect for Facebook/Instagram) for **that client's accounts only**. It never asks for
  a password. If M-0.2 found the vendor URL can't be handed to a third party, record the gap and
  fall back to an in-portal flow for logged-in client contacts.
- The portal gets a "Connect your accounts" panel for `ClientReviewer` contacts showing the same
  slots. Its existence doesn't let them see any other account data.
- Each connection notifies the account owner, writes an activity entry, and refreshes client health
  (an expired-token Red can clear).
- Send the invite email with `ClientConnectInviteMail` (Resend), in the Alima voice.
- **Done when**: tests cover the token being hashed at rest, expiry, revoke, single-client scoping (a
  token can't connect an account belonging to another client or org), the vendor being faked, the
  notification and health refresh, and portal visibility per role.

### Step M-2.9 — Approval mode semantics ✅ Done
- **Implemented via (20 Oct 2026):** Implemented via `PostStatusTransitionMap::isAllowedFor()`, Owner `overrideApproval()`, `clients.default_approval_mode`; `ApprovalModeTest`.
- Refer to **Step I-3.8** in `improvements.md`. Required before M-6.3 (staged approvals).

### Phase M-2 exit criteria
- One post can carry a different caption and first comment per platform, publish as a story,
  carousel or reel, and go to at least 9 networks, with media problems blocked before publish.
- Unscheduling, editing or pausing a vendor-held post never double-posts.
- A client connects their own accounts from a link, without sharing a password.

---

## Phase M-3 — Prove results ✅ Done

Goal: answer the question clients pay for: "did it work?". This is PRD-001's #1 gap, so it comes
straight after publishing. It also switches on the engagement parts of Campaigns v2 that are waiting
for data.

### Step M-3.1 — Engagement and account metrics ✅ Done
- **Implemented via (21 Oct 2026):** `EngagementSource` contract + `UploadPostEngagementSource`, `post_target_metrics` (append-only) / `social_account_metrics`, nightly `analytics:sync-engagement`, `EngagementMetrics` filling the `engagement` section (page, drill-down, CSV, PDF), "not available for {platform}" per the registry, campaign engagement KPIs, top posts by engagement and frozen `engagement_snapshot` on new wrap reports; `EngagementSyncTest`, `UploadPostEngagementSourceTest`, `EngagementSummaryTest`. See `analytics.md`, `campaigns.md`.
- Refer to **Step I-10.1** in `improvements.md` (which builds Phase 12 in `graphy-development-steps.md`,
  filling Phase 17's `engagement` slot).
- **MVP notes:**
  - Use the sources recorded in M-0.2. Where a platform has no metrics source, show a clear "not
    available for {platform}" state rather than zeros.
  - Turn on the campaign engagement KPIs (C-7.1: reach, impressions, engagement rate, follower
    growth). They currently return `available: false`. Rank the C-7.2 results tab's top posts by
    engagement, and include engagement in new wrap-report snapshots (C-7.3). Already-shared
    snapshots stay frozen (regression test).

### Step M-3.2 — Best time to post and top content ✅ Done
- **Implemented via (21 Oct 2026):** `BestTimeService` (weekday × 2-hour slots on the account's clock, ranked by engagement rate), editor "Best times" chips, client drill-down panel; top posts in the engagement section; `BestTimeTest`. Pillar/format/hashtag breakdowns not built (MVP note).
- Refer to **Step I-10.2** in `improvements.md`.
- **MVP note:** the MVP needs best time per account and top posts. Pillar, format and hashtag
  breakdowns can follow.

### Step M-3.3 — Client-facing monthly reports, scheduled to clients ✅ Done
- **Implemented via (21 Oct 2026):** `client_reports` + `clients.report_schedule` (review / automatic / off), `BuildClientReportSnapshotAction` (client-safe), prepare / commentary / publish (frozen) actions, `analytics:send-client-reports` (org timezone), `ClientReportPublishedMail` with the branded PDF to the client's contacts, portal Reports; shared wrap reports appear in their month; `ClientReportsTest`. See `analytics.md`, `client-portal.md`.
- Refer to **Step I-10.3** in `improvements.md` (which builds Phase 8 in `graphy-development-steps.md`).
- **MVP notes:**
  - Phase 17's scheduled report emails (Step 17.8) currently go to staff only. Add **client contacts as
    recipients**, using the client-safe report (no billing data, own client only, posts from
    "Waiting on client" onwards). That's PRD-001 gap #9.
  - Shared wrap reports appear as a section in that month's report (C-7.3 note).

### Step M-3.4 — Client health v2 ✅ Done
- **Implemented via (21 Oct 2026):** weighted reasons in `ClientHealthService` (failed publishes, overdue approvals, cadence vs `target_posts_per_week`, calendar gaps, Owner-only overdue invoices, advisory Brand Brain gaps, tokens), `clients.health_reasons`, `Client::healthFor()`, 90-day trend, queued recompute + hourly sweep; a `ClientHealthServiceTest` case per trigger. See `clients.md`.
- Refer to **Step I-6.1** in `improvements.md`. This turns client health into a real churn-prevention
  pitch: overdue approvals, failed publishes, cadence gaps and unpaid invoices all feed it.

### Phase M-3 exit criteria
- Org analytics, the client drill-down and campaign results show engagement and follower growth.
- Each client gets a scheduled, branded monthly report in the portal and by email, with no billing data.

---

## Phase M-4 — Invoicing you can actually send ✅ Done (M-4.1 adviser sign-off pending)

Goal: make "client to cash" airtight. Invoices are valid tax invoices in the UK and UAE that an org
can send to its client and get marked paid through a two-sided confirmation handshake. The org takes
the money however it prefers (PayPal, bank transfer, cash, etc.) — Graphy never processes payments.

### Step M-4.1 — VAT/tax lines, VAT/TRN numbers and per-client currency ✅ Done (adviser sign-off pending)
- **Implemented via (24 Oct 2026):** per-org `TaxRate` list (Settings, `TaxRatePolicy`, seeded UK/UAE
  defaults from `config/tax.php` + backfill), org & client VAT/TRN, per-item + invoice-level tax
  snapshots, `InvoiceTotals` (single calculator; `invoices.subtotal`/`tax_total`/`tax_breakdown`,
  `amount` = grand total), `clients.billing_currency` + `default_tax_rate_id`, retainer tax snapshot +
  mixed-currency-retainer rejection, "Tax invoice" PDF heading + VAT/TRN + tax lines, CSV Subtotal/Tax,
  campaign fee inherits the rate. Tests `InvoiceTotalsTest`, `TaxInvoicingTest`. **Partial payments,
  credit notes, discounts and custom number formats were scoped out to M-12** (MVP-narrow, per the
  decision below). The **tax adviser's exact-contents sign-off is still open** (see Decisions), so the
  feature ships with editable rates/labels and this step is "feature-complete, sign-off pending". See
  `billing.md`.
- Refer to **Step I-11.1** in `improvements.md` (tax/VAT lines, discounts, partial payments, credit
  notes, number formats, per-client billing currency).
- **MVP notes:**
  - Add the agency's **VAT registration number (UK) / TRN (UAE)** to organization settings, and the
    client's VAT/TRN to the client billing details. Both print on the invoice PDF and in the portal.
  - Tax rates come from a per-org list (seed UK 20%, 0%, exempt, and UAE 5%, 0%) rather than
    hard-coded values. An invoice shows subtotal, tax per rate and total in its own currency.
  - Use the heading "Tax invoice" when the org has a VAT/TRN number.
  - The exact required contents need confirming by the tax adviser (PRD-001 gap #3). Record the
    adviser's answer in `billing.md` before calling this step done.
  - Campaign invoicing (C-8.2) and retainer items must pick up the client's default tax rate.
- **Done when**: I-11.1's Done when is met, plus tests for VAT/TRN on the PDF, tax per rate on a
  mixed-rate invoice, the heading rule, and a campaign invoice inheriting the client's rate. Totals
  stay locked on finalize (the regression test for Phase 7).

### Step M-4.2 — Payment confirmation handshake (no online payments yet) ✅ Done
- **Implemented via (24 Oct 2026):** `InvoiceStatus::PendingConfirmation` + `payment_reported_*`
  columns; client reports from the portal (`ReportInvoicePaymentAction`, `portal.invoices.report-payment`,
  own-client + sent-only, notifies the org); org confirms via a widened `MarkInvoicePaidAction`
  (`invoices.confirm-payment`) or reverts (`RevertPaymentReportAction`, `invoices.revert-payment`),
  both `InvoicePolicy::confirmPayment`; `sent → paid` direct kept; a `pending_confirmation` invoice is
  reverted to `sent` before voiding (recorded in `billing.md`). Portal "Mark Paid" dialog + internal
  Confirm/Not-received buttons + `pending_confirmation` tone. Tests `PaymentHandshakeTest`. Graphy
  never processes money.
- **No Stripe / no online card payments in the MVP.** Graphy never collects or routes a client's
  payment. The org arranges payment with its client out-of-band (PayPal, bank transfer, cash, etc.);
  Graphy only records that it happened. The `Organization::payment_instructions` already printed on
  the invoice PDF and portal is how the org tells the client *how* to pay.
- **The handshake.** Add one intermediate invoice state between `sent` and `paid` so the client and
  the org both confirm before an invoice is locked as paid:
  1. The client reviewer, on the read-only portal invoice, gets a **"Mark Paid"** button on their
     own `sent` (and still-unpaid) invoices. Clicking it transitions `sent → pending_confirmation`
     and may capture an optional payment method/reference and note ("Paid via bank transfer, ref
     …"). It does **not** mark the invoice paid — it reports that the client believes they have paid.
  2. The org (Owner/Strategist) sees the invoice flagged "Payment reported — awaiting confirmation"
     on the ledger, client Billing tab and invoice detail, with a **"Confirm Payment"** button.
     Confirming transitions `pending_confirmation → paid` (reusing the existing paid-stamp /
     `paid_at` / `payment_reference` path and PDF-as-receipt refresh), and a **"Not received yet"**
     action reverts `pending_confirmation → sent` so the client can try again.
  3. The org can **still mark an invoice paid directly** (`sent → paid`, the existing
     `MarkInvoicePaidAction` path) when it took payment itself and never needs the client to report —
     the handshake is an additional route to paid, not a replacement.
- **State machine (extends `billing.md`'s current `draft → sent → paid` / `sent → void`):**
  `draft → sent → pending_confirmation → paid`, plus `pending_confirmation → sent` (revert),
  `sent → paid` (org direct), and `sent → void` unchanged. `pending_confirmation` is a real stored
  status (unlike derived `overdue`); decide whether a `pending_confirmation` invoice can be voided
  (recommend: revert to `sent` first) and record it in `billing.md`. Keep the existing rule that
  every invalid transition is rejected with a `422` on the `status` field.
- **Authorization:** only a client reviewer scoped to that invoice's own `client_id` can "Mark Paid"
  from the portal; only Owner/Strategist in the invoice's org can "Confirm Payment" / revert
  (mirrors `InvoicePolicy`). Add the new abilities to `InvoicePresenter::abilities()` so buttons
  render only when the transition can succeed.
- **MVP note:** this deliberately replaces the former Stripe "Card payment links" step. Online
  payment (a Stripe payment link per invoice + webhook) stays parked in the **"Online payment"
  bullet of Step I-11.2** in `improvements.md` as a post-MVP option; nothing here touches
  M-10.2 (Alima's own subscriptions).
- **Done when**: tests cover the client "Mark Paid" transition (allowed only for the reviewer's own
  `sent` invoice, rejected on a draft/paid/void/other-client invoice), the org "Confirm Payment"
  (`pending_confirmation → paid`) and "Not received" revert (`pending_confirmation → sent`), the org
  direct `sent → paid`, and that `pending_confirmation` surfaces correctly (status badge + the right
  buttons) in the ledger, client Billing tab, invoice detail and the read-only portal. Update
  `billing.md` in the same change.

### Step M-4.3 — Recurring retainer drafts ✅ Done
- **Implemented via (24 Oct 2026):** `clients.auto_invoice_retainer` + `invoices.retainer_period`,
  `GenerateRetainerDraftsAction` + `invoices:generate-retainer-drafts` (scheduled daily 06:30, drafts
  only, idempotent per client+period in the org timezone), `RetainerDraftsReady` notification. Tests
  `RetainerDraftsTest`. Auto-send is deliberately not built (drafts for review only). See `billing.md`.
- Refer to the **"Recurring retainer invoices" bullet of Step I-11.2** in `improvements.md`. Drafts
  are only for review; auto-send stays opt-in.

### Phase M-4 exit criteria
- A UK or UAE agency can issue a VAT-correct invoice in GBP or AED with its VAT/TRN number, send it
  to the client, have the client report payment from the portal, confirm that payment so the invoice
  locks as paid, and have next month's retainer drafted automatically. No card is ever charged
  through Graphy.
- The *invoice → send → client marks paid → org confirms → paid* Playwright journey passes.

---

## Phase M-5 — Content workflow

Goal: the daily loop of draft → review → schedule is faster than any rival, with a content hub, a
board, a grid, version history and the imports design agencies expect.

### Step M-5.1 — Content hub (table and board) ✅ Done
- **Implemented via (23 Oct 2026):** the agency content hub at `/content` (`ContentHubController`,
  `ListPostsAction`, `GetContentBoardAction`, `BulkUpdatePostsAction`, `ContentHubPresenter`),
  table + status board with server-side filters (client, campaign, status, platform, scheduled-date
  range, has-media, search), CSV export and personal saved views (`SavedPostView` + policy). The board
  reuses the C-4.3 `KanbanBoard`; the bulk bar (move / archive / add-to-campaign / reschedule) reuses
  `BulkTransitionPostsAction` + `ReschedulePostAction` (the C-4.6 pattern) and reports every skip.
  Agency-only (`workspace:agency` → `business.posts.index`), `portal.redirect` keeps reviewers out,
  and `PostPolicy::viewHub` gates it. Tests in `tests/Feature/Content/`. See `posts.md` › Phase M-5.
  **Assignee** filter/column and an "AI-generated" filter are deferred to M-5.4 (no `posts.assignee_id`
  / AI flag yet), noted in `posts.md`.
- Refer to **Step I-4.1** in `improvements.md`.
- **MVP note:** reuse the campaign board component from C-4.3 for the hub board rather than building
  a second one (`campaigns-enhancement.md` §1.6), and fold the C-4.6 campaign bulk actions into the
  hub's bulk-action bar.

### Step M-5.2 — Faster post creation and duplicate ✅ Done
- **Implemented via (23 Oct 2026):** create already opens the editor (`store` → `posts.edit`). Entry
  points: the client page's `NewPostDialog`, the content hub's "New post for…" client picker (M-5.1),
  an agency command-palette "New post", AI ideas, and `campaign_id`/date prefill through
  `clients.posts.store`. `DuplicatePostAction` + `posts.duplicate` copies content, media and target
  accounts into a fresh draft (never the schedule/status), reachable from the editor header and the
  content-hub row menu. See `posts.md` › Phase M-5 › M-5.2 and `tests/Feature/Posts/DuplicatePostTest.php`.
  The calendar empty-slot create is delivered in M-5.7 (I-5.1 owns it).
- Refer to **Step I-4.2** in `improvements.md`.

### Step M-5.3 — Autosave, version history, "what changed" ✅ Done
- **Implemented via (23 Oct 2026):** `post_revisions` + `PostRevisionService` (a content snapshot per
  content-changing save, deduped so autosave doesn't spam; a `shared` checkpoint on `waiting_client`);
  `UpdatePostAction` records the revision, `RestorePostRevisionAction` + `PostRevisionController`
  restore one (keeping the schedule). Editor: 5s autosave + "Saved · HH:MM" (`SaveBar`) and a History
  drawer with diffs. The portal and review link show "what changed since your last review"
  (`changes_since_review`). `BatchReviewCampaignPostsAction` (C-5.2) now judges staleness by content
  revisions, not `updated_at`. See `posts.md` › Phase M-5 › M-5.3 and `tests/Feature/Posts/PostRevisionTest.php`.
- Refer to **Step I-4.3** in `improvements.md`. C-5.2's stale-revision check should then compare
  against `post_revisions`.

### Step M-5.4 — Assignees, due dates and "My work" ✅ Done
- **Implemented via (23 Oct 2026):** `posts.assignee_id`/`reviewer_id`/`due_at`, an editor Assignment
  panel, internal-review notifications targeted at the reviewer/assignee (fallback to the org when
  unassigned), a `/my-work` page (due / to-review / assigned / mentioned, staff-only) reachable from the
  sidebar and palette, and content-hub assignee filter + column + bulk Assign. `StartPostFromDeliverableAction`
  hands the deliverable's assignee and due date (planned date − approval lead) onto the post (C-4.4).
  See `posts.md` › Phase M-5 › M-5.4 and `tests/Feature/Posts/{PostAssignmentTest,MyWorkTest}.php`.
  A dedicated "My work" default Home section for Designers can follow (access is via sidebar/palette/page).
- Refer to **Step I-4.4** in `improvements.md`. Deliverable due dates from C-4.4 hand off to
  `posts.due_at`.

### Step M-5.5 — Live per-platform previews ✅ Done (via M-1.6 + M-2.2)
- **Implemented via (verified 23 Oct 2026):** the Done-when is already met. `PreviewPanel`
  (`components/post-editor/preview-panel.tsx`) is a first-class side panel in the editor's right rail
  with one tab per target (`TargetSwitcher`), the effective caption (per-channel overrides via
  `publishedCaption`), media crop/framing, the "…more" fold (`CaptionFoldMeter`), first comment and
  vertical safe zones — all driven by the platform capability registry. The identical
  `SocialPostPreview` component is reused in the client portal (`portal/posts/show`) and the review
  link (`review/show`), so clients approve what they'll see. See `posts.md` › Phase M-1 (M-1.6) and
  Phase M-2 (M-2.2).
- **Deferred:** a true mobile/desktop toggle needs per-platform *desktop* layouts (a separate build);
  the preview is a mobile-accurate mockup labelled "a close approximation — apps vary by device".
- Refer to **Step I-4.5** in `improvements.md`.

### Step M-5.6 — Instagram grid planner (new) ✅ Done (via the Phase 18 grid planner)
- **Implemented via (reconciled 23 Oct 2026):** this branch already ships a **more capable** Instagram
  grid planner (Phase 18, `grid-planner.md`) that supersedes this step's simpler spec: it is per IG
  account (`grid_plans.social_account_id`), shows the live published feed (`include_live_feed`) ahead of
  planned/scheduled tiles in order, supports drag-reorder and sequence scheduling, links to a campaign,
  enforces role policies (Designers view; producing roles reorder) and has a read-only client portal
  review — all tested (`tests/Feature/Grids/*`). The one gap versus this step was an entry point from
  the new content hub, now added (a **"Grids"** button on `/content` → the grids hub). The step's
  `SwapPostTargetSchedulesAction` (swap two targets' scheduled times) is **superseded** by the grid
  planner's tile-reorder + re-schedule path (`GridProductionController::applySchedule`), which is the
  canonical way the branch reorders a grid. Reachable from the content hub, the client page, the
  campaign workspace and the portal, per the step's intent.
- _Original spec (delivered by the Phase 18 planner above):_ A grid view per Instagram account: a
  3-column feed mock showing published posts (latest N
  `published` targets, using M-2.4 thumbnails) followed by scheduled and draft targets in their
  scheduled order, each tile with a `StatusPill`.
- Drag to reorder **scheduled** tiles. This swaps the tiles' scheduled times through a new
  `SwapPostTargetSchedulesAction` (`DB::transaction()`, both targets locked, re-dispatching through
  the I-1.2 / M-2.5 path). Published tiles are fixed.
- Reachable from the content hub (view switcher), the client page (per account), the campaign
  workspace (filtered to the campaign) and the portal (read-only, client-visible statuses only, per
  `PostStatus::clientVisible()`).
- Policies: Designers can view but not reorder, and reordering uses `PostPolicy::reschedule`.
- **Done when**: feature tests cover grid ordering, a swap that keeps each target's local-time
  invariant, rejection of a published tile, portal scoping, and authz per role. The UI is checked at
  375 px and 1280 px.

### Step M-5.7 — Calendar interaction upgrade ✅ Done
- **Implemented via (23 Oct 2026):** drag-and-drop moved from native HTML5 DnD to `@dnd-kit`
  (pointer + touch sensors; click still follows the chip link). The week and day views are now real
  time grids (an hour row per hour + an "All day" rail), so a chip can be dropped on an exact slot.
  The month view still shifts the **whole post** by whole days (`ReschedulePostAction`); the week/day
  grid moves a **single channel** to a day + time via new `RescheduleTargetAction` +
  `posts.targets.reschedule`, leaving siblings untouched. Every movable chip also has a
  keyboard-accessible `ReschedulePopover` (the keyboard path, since the chip stays a real link).
  "Click an empty slot to create" is a hover "+" on each cell → `CalendarNewPostDialog` seeded with
  the date/time, which lazily loads the chosen client's accounts + open campaigns
  (`calendar.new-post-options`) and creates through `clients.posts.store`. Tests:
  `RescheduleTargetTest` (single-target move keeps siblings; time-grid time change; past/validation/
  authz/preview) and `reschedule-popover.test.tsx` (keyboard-only reschedule). See `calendar.md`,
  `posts.md`. Planning layers (I-5.2) and iCal/export (I-5.3) stay out of scope here.
- Refer to **Step I-5.1** in `improvements.md`.

### Step M-5.8 — Bulk import, recycling and recurring posts ✅ Done
- **Implemented via (23 Oct 2026):** Phase 10 CSV bulk import — `ParseBulkPostImportAction` (client-
  scoped CSV → `BulkPostRow`s + per-row errors, columns `caption,hashtags,accounts,date,time,format,
  first_comment,link,campaign`, optional campaign code), `CreatePostsInBulkAction` (all-or-nothing
  batch of drafts via `CreatePostAction`), `BulkPostImportController` (upload/preview/store +
  downloadable template + an on-page "Moving from another tool" guide), reachable from the client
  Posts tab. Phase 11 recycling — `is_recyclable`/`recycle_after_days`, `RecyclePostAction`,
  `posts.recycle` + `PostPolicy::recycle`, Home "Ready to recycle" bucket. **Recurring posts** —
  `posts.recurrence` (`weekly`/`monthly`), `GenerateRecurringPostDraftsAction` +
  `posts:generate-recurring-drafts` (daily; next occurrence as a draft only, month-end/DST-safe),
  editor "Repeat & reuse" panel. Per-target overrides/formats carried through import and recycling
  (I-4.7). Tests `RecyclePostTest`, `BulkPostImportTest`. See `posts.md`, `home.md`.
- Refer to **Step I-4.7** in `improvements.md` (which builds Phases 10 and 11 in
  `graphy-development-steps.md`).
- **MVP notes:**
  - The CSV import accepts an optional campaign code, so a month of campaign posts can be loaded at once.
  - Ship a downloadable CSV template and a "Moving from another tool" help page. This is a file
    import only; spec §13's "no Planable sync" still holds.
  - Add **recurring posts** on top of Phase 11: `posts.recurrence` (`weekly|monthly`, nullable) and a
    scheduled job that creates the next occurrence as a `draft` via `RecyclePostAction`, never
    straight to `scheduled`. Test the month-end and DST edges in the account's timezone.

### Step M-5.9 — Canva and cloud imports (moved forward from after launch) ✅ Done
- **Implemented via (23 Oct 2026):** a `DesignImportSource` contract (per I-15.2) with Canva, Google
  Drive and Dropbox behind it (`OAuth2ImportSource` base + `DesignImportManager`; a
  `FakeDesignImportSource` for tests), `design_import_connections` with **encrypted** OAuth tokens
  (never logged), `ImportDesignAssetAction` that runs an imported file through the normal **private**
  asset pipeline (`AssetStorage::storeContents` → signed URLs, media probe M-2.4) tagged with its
  source and optionally attached to a campaign asset kit (C-4.1). OAuth connect/callback/disconnect +
  a client-scoped files/import API (`DesignImportController`), and an "Import from Canva / Drive /
  Dropbox" dialog on the client Assets tab with a reconnect prompt when a connection dies. Figma stays
  link-only. Each source is faked in `tests/Feature/Assets/DesignImportTest.php` (private asset +
  signed URLs, per-provider source, revoked → reconnect). See `assets.md`.
- Refer to the **"Imports" bullet of Step I-7.2** in `improvements.md`: Canva first, then Google
  Drive and Dropbox, each behind its own contract per **Step I-15.2** (e.g. `App\Contracts\DesignImportSource`).
- **MVP note:** design agencies live in Canva (PRD-001 gap #5). Imported files go through the
  normal private asset pipeline (M-1.3) and media probe (M-2.4), are tagged with their source, and
  can be attached to a campaign asset kit (C-4.1). OAuth tokens are encrypted and hidden, never
  logged. Figma stays link-only.
- **Done when**: each source has a faked implementation in tests, imported assets are private with
  signed URLs, and a revoked connection fails cleanly with a reconnect prompt.

### Phase M-5 exit criteria
- A strategist can find, filter, bulk-move and assign posts in one place, plan an Instagram grid,
  restore an old version, import a month of posts from CSV, and pull a Canva design into a post.

---

## Phase M-6 — Approvals & client experience

Goal: the best approval experience on the market. A client approves from their phone in one tap and
sees exactly what will publish.

### Step M-6.1 — Client contacts ✅ Done
- **Implemented via (2026-10-23):** `client_contacts` (role, per-channel email prefs, soft deletes),
  backfilled from the legacy `approval_email` / `billing_email` (kept as fallback). `Client::reviewEmailRecipients()`
  / `invoiceRecipients()` fan review emails out to every approver contact across post, grid and
  campaign-batch paths; `ClientReportRecipients` merges them; invoices prefer the first billing
  contact. `CreateClientContactAction` / `UpdateClientContactAction` / `DeleteClientContactAction`,
  `ClientContactPolicy` (Owner/Strategist, agency-only), `ClientContactController` + Form Requests,
  routes `clients.contacts.store` / `contacts.update` / `contacts.destroy`, managed inline on the
  Portal access tab (`ClientContactsPanel`). Tests `ClientContactTest`. See `clients.md` › Phase M-6.
- Refer to **Step I-6.2** in `improvements.md`. Required before M-6.3. Contacts never count as seats (M-10.1).

### Step M-6.2 — One client experience, passwordless ✅ Done
- **Implemented via (2026-10-23):** `portal_login_tokens` + `PortalLoginToken` /
  `CreatePortalLoginTokenAction` (hashed, single-use, expiring), `GET /portal-login/{token}`
  (`Portal\MagicLinkController`) signs a reviewer into the portal landing on the emailed post;
  `SendClientReviewRequestedEmailAction` sends a magic link to contacts with portal access and the
  token-only review link to those without; invite-accept auto-logs-in → `portal.dashboard`;
  `portal.redirect` applied to the whole internal workspace group so reviewers are fully blocked from
  the internal app. Tests `MagicLinkTest`, `ClientReviewerInternalRedirectTest`. See `client-portal.md`
  › Phase M-6.
- Refer to **Step I-8.1** in `improvements.md`.

### Step M-6.3 — Multi-approver and staged approvals (any / all / sequential), approve all ✅ Done
- **Implemented via (2026-10-23):** `clients.approval_policy` (any/all/sequential) + `approval_steps`
  per post (built on entering `waiting_client`); `SubmitClientDecisionAction` is the single client-
  decision entry point (portal, review-link token, campaign batch all route through it) and releases
  a post only when the policy is satisfied; sequential order enforced; **Approve all** in the portal;
  the approval certificate PDF (`GenerateApprovalCertificatePdfAction`, Studio Paper branding) with
  the approved revision's SHA-256 hash, downloadable by the client (`portal.posts.certificate`) and
  staff (`posts.approval-certificate`). Tests `ApprovalStepsTest`. See `client-portal.md` › Phase M-6.
- Refer to **Step I-8.2** in `improvements.md`.
- **MVP note:** C-5.2 campaign batch approval goes through the new approval steps
  (`BatchReviewCampaignPostsAction` → `SubmitClientDecisionAction`). The C-2.3 brief approval has its
  own revision/stamp flow and is unchanged. That seam is documented in `client-portal.md`.

### Step M-6.4 — Visual feedback and suggested edits ✅ Done
- **Implemented via (2026-10-23):** `post_comments` extended with pins (image x/y, video timestamp,
  caption range), suggested caption edits, thread replies (`parent_id`) and resolve
  (`resolved_at`). A shared interactive `components/feedback/*` board — click-to-place image pins,
  caption select-to-comment, video timestamps, threads, resolve/reopen, one-click suggested-edit
  accept — in the editor (staff), portal (reviewers) and review link (read-only). Unresolved
  feedback blocks approval (`TransitionPostStatusAction`), overridable by an Owner.
  `ResolvePostCommentAction`, `AcceptSuggestedEditAction`, `PostFeedbackController`,
  `PostFeedbackPresenter`. Tests `VisualFeedbackTest`. See `posts.md` › Phase M-6 (M-6.4).
- Refer to **Step I-8.3** in `improvements.md`.

### Step M-6.5 — Approval SLA and reminders ✅ Done
- **Implemented via (2026-10-23):** Phase 9 — `organizations.approval_sla_hours` (Owner setting),
  `PostApprovalSlaService` (overdue = status waiting_client/changes_requested past the SLA, from the
  latest activity log), overdue flag + badge on Home and the portal dashboard, and the daily
  `approvals:send-overdue-reminders` → `SendOverdueApprovalRemindersJob` (in-app `ApprovalOverdue` to
  the studio + `ClientApprovalReminderMail` to the client's review-email contacts, once per 24h per
  post via `posts.last_reminded_at`). Tests `ApprovalSlaTest`. See `settings.md` / `home.md` /
  `notifications.md` / `client-portal.md`.
- Refer to **Step I-8.4** in `improvements.md` (which builds Phase 9 in `graphy-development-steps.md`).

### Step M-6.6 — Mobile-first portal (PWA) ✅ Done
- **Implemented via (2026-10-23):** the portal is an installable PWA (`portal.webmanifest` + the
  shared `PwaProvider` mounted in the portal layout), the portal shell is mobile-first, and the post
  page has a **swipe-to-approve** control (`SwipeToApprove`, pure `decideFromOffset` unit-tested).
  The `mobile` Playwright project (Pixel 5) runs `portal-approve-mobile.spec.ts` (invite → accept →
  approve on a phone); e2e runs in CI. The campaign review, brief approval, connect panel and
  payment flows already share the mobile-first portal shell. White-label is M-10.4. See
  `client-portal.md` › Phase M-6 (M-6.6).
- Refer to the **mobile half of Step I-8.6** in `improvements.md`.
- **Done when**: the portal passes a Playwright mobile viewport suite, including the *client approves
  via portal* journey.

### Step M-6.7 — Notification preferences and chat (WhatsApp, not Slack) ✅ Done
- **Implemented via (2026-10-23):** per-user `notification_preferences` (in-app + digest per
  category, `NotificationPreferences`), the `send`-path honouring the in-app mute, a daily
  `notifications:send-digest` → `SendNotificationDigestAction` + `NotificationDigestMail`, a paginated
  notification centre (cursor + unread filter, "Load more" in the bell), and a swappable
  `ChatNotifier` contract bound to a no-op `NullChatNotifier`. Tests
  `NotificationPreferencesTest`. See `notifications.md` › Phase M-6.7.
- **Decision (2026-10-23, Alex):** **no Slack.** Team chat integrates **WhatsApp** later, behind the
  `ChatNotifier` contract shipped here.
- Refer to **Step I-12.1** in `improvements.md`.

### Step M-6.8 — Arabic client experience [Conditional: M-0.1 "Arabic" = yes] ⏭ Skipped (M-0.1: no; revisit at M-8.3)
- First build the i18n groundwork from **Step I-14.7** in `improvements.md`, limited to the
  client-facing surfaces. Then ship an **Arabic locale with a right-to-left layout** for:
  - the portal;
  - the review link;
  - the connect page;
  - client emails (review, reminder, invite, report, invoice);
  - invoice, report and wrap-report PDFs.
- Locale per client contact (`client_contacts.locale`, defaulting to the client's
  `default_language`). Staff UI stays English.
- Poppins has no Arabic glyphs. Pick a brand-approved Arabic pairing (for example IBM Plex Sans
  Arabic) and record it in `.junie/brand-guidelines.md` before shipping. Use logical CSS properties
  (`ms-`/`me-`, `start`/`end`) so layouts mirror correctly. Numbers, dates and currency are
  locale-formatted (AED amounts must read correctly in Arabic).
- Arabic copy must be written or reviewed by a native speaker in the Alima voice. No raw machine
  translation goes live.
- **Done when**: a pseudo-locale run shows no hard-coded strings on those surfaces, Playwright
  screenshots exist in Arabic at 375 px and 1280 px, and invoice and report PDFs render Arabic
  correctly (snapshot test).

### Phase M-6 exit criteria
- A client contact opens a magic link on a phone, sees the real per-platform preview, leaves a pinned
  comment or suggested edit, and approves, in a sequential approval chain if the client has one.
- Overdue approvals nudge the right person once a day, and the team hears about it in Slack.

---

## Phase M-7 — AI control plane & edge

Goal: AI is safe to sell. It's metered, budgeted and fast, and it's clearly better than generic
rewrite buttons because it knows the brand. This has to land before design partners use AI at scale
and before any AI allowance is sold.

### Step M-7.1 — Finish the AI control plane ✅ Done
- **Implemented via (2026-11-10):** `App\Services\AI\AiAccess` is the single capability gate
  (platform flag AND per-org `organizations.ai_enabled`, Owner/Strategist to use); every agency,
  campaign and grid AI surface goes through it, so disabling either flag hides all AI for that org
  (no controller holds inline gating). Text usage is metered per token on `ai_usage_logs`
  (`prompt_tokens`/`completion_tokens`, `DeepSeekProvider::lastUsage()` → `AiCallMeter` →
  `RecordAiUsageAction`, never-throw); `organizations.ai_monthly_token_budget` enforced by
  `AiUsageGuard`/`AiBudgetExceededException` (clean 422). Feedback loop: `ai_feedback` +
  `AiRating`, `RecordAiFeedbackAction`, `AiFeedbackController` (trend/idea/caption, idempotent),
  implicit `Applied` on idea→post, `AiPerformanceService` acceptance aggregation. Settings → AI
  usage gains the enable toggle, token budget, text-usage/acceptance stats. `AiTextUsage` (B-6) and
  `CampaignAiUsage` (C-8.3) recorders are untouched (one row per call per path). Tests:
  `AiAccessTest`, `AiUsageGuardTest`, `AiPerformanceServiceTest`, `AiControlPlaneTest`. See `ai.md`
  › "AI control plane". **Scope:** business shell (B-*, out of MVP) still gates on the platform flag
  only.
- Refer to **Step I-9.1** in `improvements.md` (which builds Steps 2.7–2.9 in
  `graphy-development-steps.md`). Required before M-10.1, because plan limits read `ai_usage_logs`.
- **MVP note:** campaign AI actions already log a campaign id (C-8.3). Keep that working when text
  usage logging is unified.

### Step M-7.2 — Async AI with progress ✅ Done
- **Implemented via (2026-11-10):** captions/trends/ideas run on the queue (`App\Jobs\RunAiTextJob`)
  keyed by `ai_text_jobs` (uuid request id, idempotent dispatch); the endpoints return `202
  {request_id}`, the UI polls `ai-text-jobs.status` (outside `throttle:ai`) and can cancel
  (`ai-text-jobs.cancel`). A slow vendor never blocks the request or trips a gateway timeout. The
  campaign AI planner (C-3.3 `PlanCampaignJob`) and wrap/brief drafts were already async/queued, so
  they're unchanged. Frontend: `resources/js/lib/ai-text-job.ts` + the editor's Suggest/Cancel and the
  client Trends & Ideas panel. Tests: `AiAsyncTest`, updated `AiControllerTest`. See `ai.md` ›
  "Async text jobs".
- Refer to **Step I-9.2** in `improvements.md`. Include the campaign AI planner and wrap-report drafts.

### Step M-7.3 — Writing tools in the editor ✅ Done
- **Implemented via (2026-11-10):** `App\Contracts\WritingAssistant` (DeepSeek/Null) + `WritingTool`
  enum; `RewriteCaptionAction` (budget + meter + guardrail + platform-limit clamp) behind
  `POST posts/{post}/ai/rewrite`; the editor's "Writing tools" dropdown (shorten / longer / change
  tone / add CTA; Adapt for a single platform). **Alt text from image** via
  `GenerateAltTextForAssetAction` (reuses the B-6.2 vision path) behind `POST posts/{post}/ai/alt-text/{asset}`
  + the per-image "suggest alt text" button. **Repurpose** (adapt to every platform) was already
  shipped (B-6.4). Translate (EN↔AR) is excluded — M-6.8 was skipped. Hashtag research deferred.
  Tests: `AiWritingToolsTest`. See `ai.md` › "Writing tools".
- Refer to **Step I-9.4** in `improvements.md`.
- **MVP note:** the MVP set is *adapt to {platform}* (writes the M-2.2 override), shorten, change
  tone, alt text from image, and **Repurpose**. Translate (EN ↔ AR) is included if M-6.8 runs.
  Hashtag research can follow.

### Step M-7.4 — "Turn into post" and brand-fit score ✅ Done
- **Implemented via (2026-11-10):** "Turn into post" was already shipped (`ai-post-ideas.persist` →
  pre-filled draft in one click). Added `App\Services\AI\BrandFitScorer` — a deterministic 0–100
  brand-fit score + reason per caption suggestion, surfaced as a coloured badge in the editor; a
  `never_use`/`do_nots` phrase scores low and is already guardrail-dropped before it shows. Other
  Step 2.12 differentiators deferred (recorded in `ai.md`). Tests: `BrandFitScorerTest`,
  `AiBrandFitTest`. See `ai.md` › "Turn into post & brand-fit score".
- Refer to the "Turn into post" bullet of **Step 2.11** and the "Tone & brand-fit score" bullet of
  **Step 2.12** in `graphy-development-steps.md`.
- **Done when**: an idea becomes a pre-filled draft in one click, and each caption suggestion shows a
  0–100 brand-fit badge (tested with a faked provider, including a `weNeverSay[]` phrase scoring low
  and still being filtered out).

### Step M-7.5 — Honest trends ✅ Done
- **Implemented via (2026-11-10):** `AiTrend.source` + `ai_trends.source`; `DiscoverTrendsAction`
  labels model trends "AI hypothesis" and merges a swappable `TrendSource` (bound to
  `TopPostsTrendSource` — the client's own top posts from M-3.1, tagged "Your top posts"), with the
  cap lifted from 3 to `ai.suggestions.per_request` (6). The UI shows each trend's source and flags
  AI hypotheses as not-live-data. External trend APIs remain future work behind the same contract.
  Tests: `HonestTrendsTest` (+ updated `DiscoverTrendsActionTest`/`DeepSeekProviderTest`). See
  `ai.md` › "Honest trends".
- Refer to **Step I-9.5** in `improvements.md`. For the MVP, label trends with their source and add
  the client's own top posts as a source (from M-3.1). External trend APIs can wait.

### Phase M-7 exit criteria ✅ Met (2026-11-10)
- Step 2.13's "Done when" is met. AI runs asynchronously, every AI call is logged and budgeted, and
  the editor offers on-brand writing tools a human always confirms.
- **Verified:** per-org AI toggle + Owner/Strategist gating centralised in `AiAccess` (no inline
  controller gating); text usage metered per token on `ai_usage_logs` with a monthly token budget
  (`AiUsageGuard`, clean 422); feedback loop (`ai_feedback`, acceptance rate); captions/trends/ideas
  run async with poll + cancel; editor writing tools (shorten/tone/adapt/CTA, alt-text-from-image) +
  Repurpose; 0–100 brand-fit badge per caption; trends labelled by source with model ones marked
  "AI hypothesis" and the client's own top posts as a real-signal source; suggestion volume lifted to
  5–10. No API keys/prompts leak to client props or logs. See `ai.md` › "AI control plane" through
  "Honest trends".

---

## Phase M-8 — Design-partner release

Goal: put the product in front of up to 5 design partners on the approved offer (free for 3 months,
then 50% off for 12). This needs no subscription billing. What we learn sets the inbox decision and
validates pricing before public launch.

### Step M-8.1 — Partner-ready deployment 🟡 Partial (app flag done; infra/S3 deferred)
- Production environment with S3, Redis/Horizon, the scheduler heartbeat, error tracking and alerts
  (M-1), backups and a tested restore, and Meta app review approved for every scope used so far
  (M-0.2).
- Super admin creates partner orgs by hand (Phase 15 onboarding). An org-level `is_design_partner`
  flag (Pennant or an org column) is set so M-10 can apply the right coupon later.
- **Implemented via (24 Oct 2026):** the `organizations.is_design_partner` column (migration
  `2026_11_11_100100`), set on the org detail form (`UpdateOrganizationAction`/`Request`), with
  `Organization::isDesignPartner()` + `designPartners()` scope + factory state. The deployment /
  S3 / restore-drill / Meta-review parts are **deferred** (S3 is on hold per the 20 Oct decision;
  the rest are ops/launch tasks, not code). See `super-admin.md`, `organizations-users.md`.
- **Done when**: a restore drill is logged, and a partner org can go from invite to a published post
  and a sent VAT invoice on production.

### Step M-8.2 — Feedback capture ✅ Done
- An in-app "Send feedback" panel (staff only) that stores `product_feedback` (`org_id`, `user_id`,
  `page`, `message`, `created_at`) and notifies super admin. No third-party widget, and no PII
  beyond the user id.
- Partner usage view in super admin: weekly active users, posts published, approvals, invoices sent
  and AI calls per partner org, reusing the Phase 15 platform analytics core.
- **Implemented via (24 Oct 2026):** `product_feedback` + `ProductFeedback`,
  `SubmitProductFeedbackAction` (transactional, notifies super admins via
  `ProductFeedbackReceived`), `ProductFeedbackController@store` (`POST /feedback`, staff-only),
  the `FeedbackDialog` sidebar panel; the super-admin partner usage view
  (`GetPartnerUsageAction` reusing the analytics core, WAU via `users.last_active_at` +
  `TrackUserActivity`) and feedback inbox (`ListProductFeedbackAction`) under a new console chapter
  05 "Partners". Tests: `ProductFeedbackTest`, `PartnerUsageTest`, `UserActivityTrackingTest`. See
  `super-admin.md`.
- **Done when**: feedback is scoped and stored with an isolation test, and the usage view matches seeded data.

### Step M-8.3 — Decision gate 🟡 Inbox recorded (= go); rest on hold
- **Recorded (24 Oct 2026):** the Inbox decision is **go** (M-9 proceeds) — see **Decisions ›
  M-8.3** below. The Arabic, pricing and friction-point reviews stay **on hold** until partners have
  run real work (by design — there's no partner evidence yet).
- After at least 3 partners have run 2 full weeks, record in **Decisions**:
  - **Inbox:** go/no-go for M-9 (go if at least 2 partners run community management for clients);
  - **Arabic:** confirm or revise M-6.8;
  - **Pricing:** answers to "which tools would you drop?" and "what's your monthly software spend?"
    (PRD-001 §6.6), and whether the tiers stand;
  - the top 5 friction points, each turned into a step in this phase or deferred, with a reason.
- **Done when**: each decision is recorded with the date and evidence.

### Phase M-8 exit criteria
- Partner agencies run real client work on Alima weekly, and the inbox, Arabic and pricing decisions
  are recorded.

---

## Phase M-9 — Social inbox ✅ Done [Conditional: M-8.3 "Inbox" = go; otherwise move to M-12]

Goal: one place to handle comments, with on-brand AI draft replies that a human always sends.

### Step M-9.1 — Comment ingestion ✅ Done
- **Implemented via (2026-11-12):** `App\Contracts\CommentSource` → `UploadPostCommentSource` (seven
  networks; reads `/uploadposts/comments`, replies via `/comments/create`) + `NullCommentSource`, bound in
  `AppServiceProvider`. `config/platforms.php` gained a `comments` flag (false for Pinterest + Google
  Business) surfaced via `PlatformCapabilities::supportsComments()`. Threaded storage
  (`inbox_conversations` / `inbox_messages`), `IngestCommentsForTargetAction` (idempotent upsert +
  deterministic `SentimentClassifier`), the hourly `inbox:sync-comments` →
  `SyncOrganizationCommentsJob` → `SyncOrganizationCommentsAction` sweep + manual `inbox/refresh`.
  Tests `CommentIngestionTest`, `SentimentClassifierTest`. See `inbox.md`.
- Refer to **Step 3.1** in `graphy-development-steps.md`, then the ingestion part of **Step I-15.1**
  in `improvements.md`.
- **MVP note:** the `CommentSource` contract keeps the Meta Graph fallback a drop-in if Upload-Post's
  IG/FB comments prove unreliable in the sandbox. DMs deferred behind the same seam.

### Step M-9.2 — Inbox UI, assignment and saved replies ✅ Done
- **Implemented via (2026-11-12):** the `/inbox` two-pane triage page (new chapter 04) — `InboxController`
  + `InboxPresenter`, `ListInboxConversationsAction` (server-side filters: client, platform, sentiment,
  status, assignee, reply-needed, search; paginated, eager-loaded), assignment
  (`AssignConversationAction`), snooze/resolve/reopen (`TransitionConversationAction`), per-org saved
  replies (`SavedReply` + `SaveSavedReplyAction` + `SavedReplyController`), deterministic sentiment tags,
  and the reply SLA (`InboxSlaService`, `organizations.inbox_sla_hours`, daily
  `inbox:send-overdue-reminders` → `InboxReplyOverdue`). `InboxConversationPolicy` / `SavedReplyPolicy`
  gate it; `ClientReviewer` is blocked (policy + `portal.redirect`). Tests `InboxControllerTest`,
  `InboxSlaTest`. See `inbox.md`.
- Refer to **Step I-15.1** in `improvements.md` (assignment, saved replies, sentiment tags, SLA
  timers). `ClientReviewer` never sees the inbox.

### Step M-9.3 — AI draft replies (human sends) ✅ Done
- **Implemented via (2026-11-12):** `App\Contracts\ReplyAssistant` → DeepSeek/Null; `DraftInboxReplyAction`
  (AiAccess gate, AiUsageGuard budget, metered as `inbox_reply`, guardrailed) behind
  `POST inbox/{conversation}/ai/draft` (`throttle:ai`) — returns text only, never sends. The single
  vendor-reply path is the human-only `SendInboxReplyAction` (`POST inbox/{conversation}/reply`). Tests
  `AiDraftReplyTest` (draft makes zero `reply()` calls; a `never_use` phrase filtered out; over-budget 422;
  AI-disabled 404), `InboxReplyTest`. See `inbox.md`, `ai.md`.
- Refer to **Step 3.2** in `graphy-development-steps.md`.
- **MVP note:** uses the existing DeepSeek provider, prompt built via `ClientContextBuilder`, drafts
  passed through `AiGuardrailService`, budgeted/logged through M-7.1. Sending always needs an explicit
  human action.
- **Done when**: ✅ a test proves no code path sends a reply without a user-initiated request, and
  guardrails filter a `weNeverSay[]` phrase out of a draft.

### Phase M-9 exit criteria ✅ Met (2026-11-12)
- Phase 3's goal is met for every platform M-0.2 marked supported (seven networks), and replies always
  need an explicit human send (tested — the AI draft action makes zero vendor-reply calls).

---

## Phase M-10 — Sell it

Goal: an agency can find us, start a trial, onboard, pay in GBP or AED and be held to plan limits
without us stepping in.

### Step M-10.1 — Agency plans and entitlements
- Refer to **Step B-9.1** in `business-workspaces.md` for the `Entitlements` contract, but implement
  **agency plans only**: `studio`, `agency`, `agency_plus` and `group` (custom), with the limits from
  M-0.1, in `config/plans.php`.
- Limited features: active client **brands**, staff **seats**, social profiles per brand (fair use,
  soft warning before hard stop), AI text credits, AI image credits and storage. **Never limited:**
  posts, client reviewers and client contacts.
- **Back the existing `CampaignFeatures` contract (C-9.4) with `Entitlements`** (a
  `PlanCampaignFeatures` implementation), so the AI campaign planner, wrap reports, templates,
  comparison and invoices from campaigns gate by plan with no controller changes. Saved and
  scheduled reports gate the same way.
- Over-limit behaviour: data is never deleted. Extra brands become read-only and extra accounts pause.
- Add-ons (extra 5 brands, extra seat, white-label, AI image pack) are plan modifiers that add to
  the limits. The AI image pack is only sellable after M-7.1.
- Fold AI credits into the M-7.1 budget (the B-9.2 approach: counters derived from `ai_usage_logs`).
- **Done when**:
  - each limited feature has a test: at the limit it's allowed, over it it's rejected with a
    friendly upgrade prompt;
  - posts, client reviewers and the client-to-cash core (approvals, portal, invoicing, health,
    publishing) never check a plan limit;
  - each gated campaign feature returns 403 on Studio and works on Agency;
  - add-ons raise the right limit.

### Step M-10.2 — Subscriptions with Cashier
- Refer to **Step I-11.3** in `improvements.md`, and to **Step B-9.3** in `business-workspaces.md` for
  the webhook, downgrade and Pennant-flag details.
- **MVP notes:**
  - Stripe prices in **GBP and AED**, monthly and annual (10 months for 12).
  - **14-day trial with no card.** Use a Cashier generic trial; at trial end, ask for payment and
    make the workspace read-only, never delete it.
  - Coupons and promotion codes:
    - **design partner**: 3 months free, then 50% off for 12 months, max 5, auto-applied to
      `is_design_partner` orgs;
    - **founding agency**: 25% off for 12 months, first 20, closes at public launch. **Only create it
      once Alex and Rimma have approved it** in Decisions.
  - VAT on Alima's own subscriptions (Stripe Tax or manual rates, UK and UAE registration) follows
    the tax adviser's answer, recorded in Decisions. This is separate from M-4.1 (agencies'
    invoices to their clients).
- **Done when**: I-11.3's and B-9.3's Done when are met for agency plans, coupon tests cover both
  offers (including the cap of 5 and of 20), and super admin MRR comes from subscriptions.

### Step M-10.3 — First-run onboarding
- Refer to **Step I-13.1** in `improvements.md`.
- **MVP note:** add optional steps for "Import from CSV" (M-5.8), "Send your client a connect link"
  (M-2.8), "Add your VAT/TRN number" (M-4.1) and "Start your first campaign" (C-9.3 guidance).

### Step M-10.4 — White-label portal and PDFs
- Refer to **Phase 14** (Steps 14.1–14.3) in `graphy-development-steps.md` and the white-label half of
  **Step I-8.6** in `improvements.md`. Included in Agency Plus, and an add-on elsewhere (M-10.1).
- **MVP note:** logo, colours and sender name are enough. A custom portal domain and custom email
  domain can follow (M-12).

### Step M-10.5 — Pricing and comparison pages (new)
- Extend the public marketing pages (`marketing.md`) with:
  - `/pricing`: the M-0.1 tiers, read from `config/plans.php` (the same source as M-10.1, so the page
    can't drift from what's enforced), with a **GBP/AED switch**, annual savings, "unlimited posts",
    "free unlimited client reviewers" and "analytics included" as the lead messages, plus the add-ons
    and the 14-day trial;
  - `/compare/planable`: an honest feature table plus a worked cost example for a 5-person,
    6-client agency (PRD-001 §5.1). Competitor facts sit in a dated content file with a "Last
    checked" date on the page, must be re-verified before each publish, and carry no invented
    claims. Don't publish a Wiro comparison while Wiro is waitlist-only: its claims are unverified.
- Copy follows the Alima voice. Headlines are sentence case ending with a full stop. House components
  and semantic tokens only.
- **Prices aren't public until Alex approves them** (PRD-001 §6.7). The page is behind a Pennant flag
  until then.
- **Done when**: feature tests render both pages in both currencies, a test asserts the values come
  from `config/plans.php`, and the UI is checked at 375 px and 1280 px in both themes.

### Step M-10.6 — Abuse, cost safeguards and data rights
- Refer to **Step B-13.1** in `business-workspaces.md` (signup and cost guards, applied to agency
  signup), **Step B-13.2** (export and delete the workspace), and **Step I-16.3** in `improvements.md`
  (GDPR tooling, retention and the processor record).
- Publish the terms of service, privacy policy and DPA pages (content supplied by Alex; this step
  builds the pages and links them from signup and the footer).

### Phase M-10 exit criteria
- A stranger can land on `/pricing`, start a no-card trial, onboard through to a scheduled post and
  a sent invoice, pay in GBP or AED, hit a plan limit with a clear upgrade prompt, and export or
  delete their workspace.

---

## Phase M-11 — Public launch

Goal: prove the whole product works end to end on a phone and a laptop, then ship.

### Step M-11.1 — Accessibility, responsive and dark-mode pass
- Refer to **Steps I-14.4 and I-14.5** in `improvements.md`, limited to the MVP surfaces: content hub,
  editor, calendar, grid, campaigns workspace, portal (and Arabic portal if M-6.8 ran), connect page,
  inbox, analytics, reports, invoices, onboarding and pricing.

### Step M-11.2 — Critical journeys in CI
- Finish **Step I-14.6** in `improvements.md`: sign up → first post scheduled, client approves via
  portal, publish failure → retry, invoice → send → client marks paid → org confirms → paid (no card
  charged). Add *trial → paid plan* (faked Stripe, Alima's own subscription — not client invoices),
  *client connects an account by link*, *campaign brief → approval → wrap report shared* and, if M-9
  ran, *comment → AI draft → human sends*.

### Step M-11.3 — Launch checklist (new)
- Run the full gates (`composer ci:check`, Vitest, Playwright with axe), then the
  `graphy-convention-reviewer` and `graphy-ui-reviewer` agents on the MVP diff, and fix everything they find.
- Do a seeded load check of Home, the content hub, the calendar, campaigns and the scheduler on a
  50-client, 20k-post org (the `improvements.md` budgets).
- Production readiness:
  - backups and a restore drill;
  - scheduler heartbeat alerting;
  - Meta app review approved for every scope;
  - Upload-Post production plan sized for the expected orgs, with margin per tier checked against
    M-0.2's cost;
  - Stripe live mode for GBP and AED;
  - the tax adviser's sign-off on M-4.1 and M-10.2;
  - prices approved (Decisions).
- **Done when**: every item is ticked in the **Launch log** section, with dates.

### Phase M-11 exit criteria
- The MVP is live, at least one agency pays list or founding price, and every MVP module doc matches
  the code (`sync-module-docs` reports no drift).

---

## Phase M-12 — After launch (MVP+)

Goal: deepen the "agency OS" claim and match rivals' integrations once paying customers are in.
These steps don't block launch. Order them by customer demand.

### Step M-12.1 — Public API and outbound webhooks
- Refer to **Step I-13.3** in `improvements.md` (which builds Phase 13 in `graphy-development-steps.md`).

### Step M-12.2 — MCP server
- An MCP server on top of the M-12.1 API, so agencies can use Alima from their AI assistants.
  Authenticate with the same org-scoped Sanctum tokens, using token abilities (`read`, `drafts:write`).
- Tools: list clients, list campaigns, list posts by status or date, get a post, **create a draft
  post**, add a comment, and fetch the analytics summary. Nothing publishes, approves, schedules or
  replies. A draft created over MCP lands in `draft` and goes through the normal workflow.
- `ClientReviewer` tokens are never issued. Billing data is only exposed to tokens owned by an Owner.
  Rate limited per token, and every call is audit-logged with the token id (never the token itself).
- **Done when**: tests cover each tool's org scoping, a revoked token is refused, there is no
  publish/approve/schedule tool, and `drafts:write` is required to create a draft.

### Step M-12.3 — Accounting sync
- Refer to the **"Accounting sync" bullet of Step I-11.2** in `improvements.md`, behind the
  `AccountingProvider` contract.
- **Note:** use the order **Xero (UK), then Zoho Books (UAE)**, then QuickBooks. This replaces I-11.2's
  Xero → QuickBooks order, because of the UK + UAE focus (PRD-001 gap #10).

### Step M-12.4 — Light time tracking and retainer burn ➡️ Moved to Phase M-13
- Expanded into its own module, **Phase M-13 — Time & profitability** (22 Oct 2026). Every point in
  the original step (time entries, retainer burn, the health reason, campaign cost, billable entries
  to invoice lines, role visibility) is covered there. Don't build it here.

### Step M-12.5 — Group tier: multi-agency accounts [Conditional: a real Group prospect]
- Refer to the **multi-org membership part of Step I-12.3** in `improvements.md`, then add a
  `group` plan that pools limits across the member orgs.

### Step M-12.6 — Remaining deferrals
- If M-9 was skipped, run it here.
- Also: custom portal and email domains (rest of M-10.4), Microsoft Teams (M-6.7), Arabic staff
  interface, hashtag research and translation (M-7.3), and pillar, format and hashtag performance
  (M-3.2). Each runs as its own step when there's demand.

---

## Phase M-13 — Time & profitability (new)

Goal: an agency can see, per client, whether a retainer is making money. Staff log time with almost
no friction. Hours are set against what each retainer covers. Owners get an honest margin per
client, campaign, content format and month, with early warnings before a client is over-serviced.
This closes PRD-001 gap #11 and takes the "client to cash" pitch further: agency ops tools (Productive,
Teamwork, Scoro, Accelo) track time but don't publish, and social tools publish but can't tell you
whether a client is profitable.

New module doc: `.junie/modules/time-tracking.md` (created in M-13.2). Steps that change clients,
billing, campaigns, analytics or the portal also update those module docs.

**Rules for this phase (on top of the cross-cutting rules):**

- **Hours are not billing data. Rates, cost and margin are.** Hours logged and retainer burn are
  visible to Owner/Strategist for every client. Retainer revenue and effective hourly rate follow
  `ClientPolicy::viewBilling` (Owner/Strategist). Staff cost rates, cost, margin and anything derived
  from them are **Owner-only**, in line with `viewAiCost`. Designers and Viewers only see their own
  entries. A `ClientReviewer` sees nothing unless M-13.11 is switched on for their client, and even
  then never sees rates, cost, margin or staff names.
- **Money is never summed across currencies** (the `analytics.md` rule). Cost rates live in the
  org's default currency, and retainers live in the client's `billing_currency` (M-4.1). Conversion
  only happens through the org's own recorded rates (M-13.6), and the rate is always shown next to the
  converted figure.
- **Workload, not a leaderboard** (the `analytics.md` rule). Staff are listed by name and never
  ranked, scored or given a "most profitable person" view.
- **Time is stored raw and reported rounded.** Entries keep exact minutes. The org's rounding rule
  only applies to reports and invoice lines, so changing the rule never rewrites history.
- **Closed months are frozen.** Once a month is closed (M-13.6), its entries are locked and its
  profitability is snapshotted, like a finalized invoice. A later rate change never alters a closed month.
- **Nothing is logged for a person automatically.** Suggestions (M-13.4) and timers (M-13.3) always
  need a human to confirm. AI (M-13.12) only drafts.

### Step M-13.1 — Decisions for time & profitability
- Record in **Decisions** (each with who approved it and the date, or "pending"):
  - **Gating** (follows the M-0.1 gating rule): recommend that time logging, timesheets and retainer
    burn are on **every plan**, because burn feeds client health, which is client-to-cash core. The
    profitability analytics (M-13.7–M-13.9), overage invoicing (M-13.10) and the AI brief (M-13.12)
    are time-savers, gated to **Agency and above**.
  - **Currency:** cost rates in the org default currency. A client billed in another currency gets
    margin through the org's manual exchange rate (M-13.6). Without a rate, it shows hours and
    effective rate in the client's currency and says "Add an exchange rate to see margin." It never
    shows a silent zero.
  - **Rounding default:** none, 6, 15 or 30 minutes (recommend **none**, with 15 as the most common choice).
  - **Revenue basis for margin:** recommend *retainer monthly equivalent* (the existing analytics
    rule: quarterly ÷ 3, annual ÷ 12) **plus** non-retainer invoice lines issued for that client in
    the month (fixed-fee campaigns, overage, one-offs), with void invoices excluded. Project-based
    clients use invoiced lines only.
  - **Portal hours summary (M-13.11):** off by default, switched on per client by an Owner.
  - **Timing:** this phase doesn't block launch. Recommend running it straight after M-11, alongside
    M-12. It can be pulled ahead of M-8 if design partners rank it in their top 5 at the M-8.3 gate.
- **Done when**: each item is in Decisions with an owner and a date (or "pending").

### Step M-13.2 — Time entries, categories and policy (core)
- `time_categories` (`org_id`, `name`, `colour` from the campaign palette, never Signal Orange,
  `default_billable` bool, `sort`, `archived_at`). Seed defaults per org: Strategy, Content creation,
  Copywriting, Design, Video, Community management, Reporting, Client meetings, Account management,
  Admin (non-billable). Org-editable in Settings. A category in use is archived, never deleted.
- `time_entries`: `org_id`, `user_id` (`nullOnDelete`, with `user_name_snapshot` so removed staff
  still read correctly), `client_id` (required, so internal time uses an org "Internal" pseudo-client,
  `clients.is_internal`, excluded from client lists, health and billing), nullable `campaign_id`,
  `post_id`, `time_category_id`, `worked_on` (local date in the org timezone), `started_at` /
  `ended_at` (nullable UTC, set by timers), `minutes` (unsigned int, raw), `note`, `billable` bool,
  `source` (`TimeEntrySource`: manual, timer, timesheet, suggestion, import), `invoice_item_id`
  (nullable, M-13.10), `locked_at` (nullable, M-13.6), soft deletes. Indexes: `(org_id, worked_on)`,
  `(client_id, worked_on)`, `(user_id, worked_on)`, `(campaign_id)`, `(post_id)`.
- Actions: `CreateTimeEntryAction`, `UpdateTimeEntryAction`, `DeleteTimeEntryAction`. The campaign
  and post must belong to the chosen client. A locked or invoiced entry can't be changed (422 on
  `minutes`). Each action logs `time_entry.*` with entry, user and client ids.
- `TimeEntryPolicy`: create (Owner, Strategist, Designer), view and edit **own** (anyone who can
  create), view all and edit others' (Owner, Strategist), edit locked (nobody; reopen the month in
  M-13.6 first). Viewer and `ClientReviewer` are always denied. A `TimeEntryPresenter::abilities()`
  so buttons only render when the action can succeed.
- A "Time" page (`/time`, sidebar under the operations chapter) listing entries with filters (person,
  client, campaign, category, billable, date range), and a day total with a weekly total.
- Create `.junie/modules/time-tracking.md` and add its row to `.junie/modules/README.md` **in the
  same change** (`ModuleDocsTest` fails otherwise).
- **Done when**: CRUD tests cover own vs others' entries for every role, a foreign client, campaign
  or post id being a validation error, locked and invoiced entries being rejected, the "Internal"
  client being hidden from client lists and health, and category archive vs delete. An org isolation
  test covers every new route.

### Step M-13.3 — Timers and logging where the work happens
- **One running timer per user**, stored server-side (`time_timers`: `user_id` unique, `client_id`,
  nullable `campaign_id` / `post_id` / `time_category_id`, `note`, `started_at`). It survives reloads
  and devices. `StartTimerAction` stops any running timer first (saving it as an entry) in one
  `DB::transaction()`. `StopTimerAction` writes the entry, rounding seconds up to the minute.
- A compact **header timer** in the app shell, visible on every staff page: start, stop, switch
  client, plus a "resume last" list of the last five client/category pairs.
- **"Track time" entry points** that pre-fill the context: the post editor (client, campaign and
  post), the campaign workspace, a deliverable card, the client page, and "My work" (M-5.4). Each also
  offers "Log time" for a manual entry.
- **Forgotten-timer guard:** a timer still running after the org's limit (default 10 hours) notifies
  the user and, at 14 hours, stops itself at the limit with the entry flagged "Check this entry".
  Nothing is discarded.
- A timer running past local midnight is saved against its **start** date (org timezone). The entry
  note says it crossed midnight.
- **Done when**: tests cover start/stop/switch (one running timer, the previous one saved), the
  cross-midnight and DST edges in the org timezone, the forgotten-timer cap, and that a timer can't
  point at another org's client. The header timer is checked at 375 px and 1280 px.

### Step M-13.4 — Weekly timesheet, suggestions and nudges
- **Weekly timesheet** (`/time/week`): rows are client + category (and optional campaign), columns
  are days, with cells in `h:mm`. Includes keyboard entry, "Copy last week's rows" (rows only, never
  hours), daily and weekly totals against the user's capacity, and a week picker. Saves through
  `SaveTimesheetWeekAction` in one transaction. Locked days are read-only.
- **Suggested entries:** `TimeSuggestionService` reads the user's own activity for a day (posts edited
  or moved through statuses from `post_activity_logs`, campaign activity, approvals handled) and
  suggests "You worked on 4 posts for Bloom Café today. Log time?" with client, campaign and a
  category guess. It never guesses a duration. One click opens a pre-filled entry, and suggestions
  are dismissible. No AI is needed here.
- **Missing time nudge:** a weekly in-app (and optionally email) reminder on the org's chosen day,
  for staff who logged less than an org-set share of their capacity. It honours notification
  preferences (M-6.7), can be switched off per org, and is never sent to Viewers.
- `users.weekly_capacity_minutes` (nullable, default from an org setting, e.g. 37.5 h) and
  `users.tracks_time` (bool, default true, so contractors or Owners can opt out of nudges).
- **Done when**: tests cover saving a week (create, update and clearing a cell deletes the entry),
  locked days being rejected, suggestions only using the viewer's own activity in their own org, a
  nudge respecting preferences and opt-out, and capacity defaults.

### Step M-13.5 — Retainer scope, burn and client health
- Client billing gains: `retainer_hours` (decimal 6,2 per billing cycle, nullable),
  `retainer_rollover` (`RetainerRollover`: none, next_cycle), `overage_rate` (decimal 10,2 in the
  client's `billing_currency`, nullable), and `burn_alert_percent` (default 80). These are edited on
  the client billing panel and are billing-gated like `retainer_amount`.
- `RetainerBurnService::forCycle(Client, ?CarbonImmutable $at)` returns: cycle start/end (from
  `billing_cycle`, in the org timezone), hours included (+ rolled-over hours), hours used (billable
  and non-billable split), percent used, **pace** (percent used ÷ percent of cycle elapsed),
  projected hours at cycle end at the current pace, and over/under hours. Project-based and
  hours-less clients return hours used only.
- **Rollover:** with `next_cycle`, unused hours carry into the next cycle only (never compounding)
  and expire after it. This is computed, never stored, so it can't drift.
- **Client health reasons** (via `ClientHealthService`, M-3.4): Amber "On pace to use 130% of this
  month's hours." when projected burn is over 110% with at least 5 working days of data. Amber "80% of
  this month's hours used with 12 days to go." at the alert threshold. Both are visible to
  Owner/Strategist only (a new `billing_only` visibility next to `owner_only`). Burn is never Red on
  its own: over-servicing is a margin problem, not a relationship risk.
- **Notifications:** at `burn_alert_percent` and at 100%, once per cycle, to the client owner and
  Owners (`RetainerBurnThreshold`).
- **Done when**: tests cover the burn maths for monthly, quarterly and annual cycles across a cycle
  boundary in the org timezone, rollover (carried once, never compounded), pace and projection with
  too little data, each health reason firing and its visibility per role, and notifications sent once
  per cycle.

### Step M-13.6 — Cost rates, month close and the profitability engine
- `user_cost_rates` (`user_id`, `hourly_cost` decimal 10,2 in the org default currency,
  `effective_from` date). These are **effective-dated**, so a raise only changes months from that
  date. There's an org fallback **blended cost rate** for users with no rate and an org **overhead
  percent** (loaded cost = rate × (1 + overhead)). The Settings UI is Owner-only.
- `org_exchange_rates` (`from_currency`, `to_currency`, `rate`, `effective_from`): Owner-entered
  and manual, with no live FX feed. Every converted figure shows the rate used.
- `ProfitabilityCalculator` (service, behind `App\Contracts\ProfitabilitySource` so the closed-month
  snapshot and live calculation share one interface) returns per client and month: revenue (per the
  M-13.1 basis), hours (billable / non-billable), **labour cost**, **AI cost** (from
  `ai_usage_logs.cost_estimate` for that client, converted from USD), **margin** and **margin %**,
  **effective hourly rate** (revenue ÷ hours), and **over-servicing cost** (non-billable hours beyond
  the retainer × loaded cost).
- **Month close:** an Owner closes a month (`time_periods`: `org_id`, `month`, `closed_at`,
  `closed_by`). `CloseTimePeriodAction` locks the month's entries (`locked_at`) and writes
  `client_profitability_snapshots` (one row per client and month with every figure above plus the
  rates used) in one `DB::transaction()`. Reopening is Owner-only, logged with a reason, clears the
  locks and deletes that month's snapshots. Reports read snapshots for closed months and live figures
  for open ones, and label which is which.
- **Done when**: tests cover an effective-dated rate change mid-month (each entry costs at the rate
  in force on its `worked_on`), the fallback rate, overhead, conversion with and without a recorded
  rate (no rate means a "needs a rate" state, never zero), the over-servicing figure, AI cost
  attribution, close then edit rejected then reopen then edit allowed, and snapshots being unchanged
  by a later rate change.

### Step M-13.7 — Client profitability tab
- A **"Time & profit"** tab on the client page, built with `Page`/`PageHeader`/`Panel`/`StatStrip`:
  - **StatStrip:** hours used vs included, pace, effective hourly rate, and (Owner only) margin % for
    the current cycle.
  - **Burn-down chart:** hours used per day against the even-pace line for the current cycle, with
    the projection dashed to cycle end and a marker at the alert threshold.
  - **12-month trend:** hours, revenue and (Owner) margin % per month. Closed months are solid and
    the open month is hatched and labelled "In progress".
  - **Breakdowns:** by category, by person (sorted by name, hours only for non-Owners), by campaign,
    and **time per published post by format** (feed, reel, story, carousel, short; M-2.3), e.g. "A
    reel for this client takes 3 h 10 m on average."
  - **Renewal helper** (Owner only): "At your 40% target margin and last 3 months' hours, this
    retainer would need to be £2,850 a month (now £2,200)." It uses the org `target_margin_percent`
    setting. It's an internal figure only and is never sent anywhere automatically.
  - **Scope creep signal:** a note when hours have risen three closed months in a row with no
    retainer change.
- Every figure the viewer isn't allowed to see is left out of the Inertia props (never just hidden
  in React). Status uses `StatusPill`. One hero accent. Headlines follow the brand rules.
- **Done when**: feature tests assert the props per role (Owner gets margin and renewal, Strategist
  gets hours, revenue and effective rate but no cost, Designer and Viewer get no tab), the per-format
  average excludes posts with no linked time, and the UI is checked at 375 px and 1280 px in both themes.

### Step M-13.8 — Org profitability and team analytics
- Two new org analytics sections, added to `OrganizationAnalyticsFilters::METRICS` and gated in
  `AnalyticsAccess`:
  - **`time`** (Owner/Strategist): hours by client, category and campaign. **Team utilisation**:
    billable %, logged vs capacity per person, sorted by name and never ranked, with a person's own
    figures always visible to them on `/time`. Clients over or near their retainer hours. Missing
    time by week.
  - **`profitability`** (Owner only): margin and margin % per client, effective hourly rate,
    over-servicing cost, and AI cost share. A **client quadrant** plots revenue against margin %,
    with bubble size as hours, labelled "Keep and grow", "Healthy but small", "Reprice or rescope"
    and "Review". There's also **margin by content format** across clients, and the trend by month.
- Reuses `AnalyticsRange` (org timezone), `AnalyticsScope` (client and campaign filters),
  `AnalyticsCsv` and the report PDF. Both sections can be saved and scheduled (Step 17.8). The
  `profitability` section is only offered to Owner recipients, and a saved report never sends it to a
  non-Owner (regression test).
- Home "needs attention" gains "3 clients are on pace to go over their hours." for Owner/Strategist.
- **Done when**: tests cover each section's figures against seeded data, per-currency output (never
  a mixed-currency total), section visibility per role including scheduled sends, the quadrant
  thresholds, utilisation being name-sorted with no rank field in the payload, and CSV/PDF output.

### Step M-13.9 — Campaign profitability and estimates
- Extend the C-8.3 Commercials panel: **labour cost** and **hours** next to fee, invoiced, paid and AI
  spend, giving **campaign margin** (Owner only). For an `included_in_retainer` campaign, show
  "Used 18 h of the client's retainer (34% of October)." instead of a margin.
- **Estimates:** `campaigns.estimated_minutes` and `campaign_deliverables.estimated_minutes`
  (nullable). The AI planner (C-6) may *suggest* an estimate per deliverable from this org's past
  per-format averages (M-13.7). It's a suggestion only and a human saves it. The board shows estimate
  vs actual per deliverable, and the campaign shows variance.
- Wrap reports (C-7.3) gain an **internal-only** time block (hours and variance, plus margin for
  Owners) that is **never** part of the shared client snapshot (regression test against
  `report.shared`).
- Campaign comparison (C-8.x) adds hours, margin and variance columns, with the same visibility rules.
- **Done when**: tests cover the margin maths, the included-in-retainer wording, estimate vs actual,
  the AI suggestion never saving on its own, and a shared wrap report containing no time or money figures.

### Step M-13.10 — Overage and billable time to invoices
- `BuildTimeInvoiceLinesAction(Client, period)` turns time into **draft** invoice lines on an existing
  draft or a new one, never a finalized one:
  - **Overage:** hours over the retainer (after rollover) × `overage_rate`, as one line, e.g.
    "Additional hours, October 2026: 6.5 h at £60/h". Rounding follows the org rule.
  - **Billable non-retainer work:** for project-based clients or entries marked billable outside the
    retainer, one line per category or per campaign (the org chooses), with the hours in the description.
- Linked entries get `invoice_item_id` and are locked. Deleting the draft line, or voiding a never-sent
  invoice, unlinks them. A finalized invoice's lines never change (the Phase 7 regression test).
- M-4.3's recurring retainer drafts gain an opt-in "Add last cycle's overage" setting per client
  (`clients.auto_invoice_overage`). It's still a draft for review and is never auto-sent.
- Lines inherit the client's default tax rate and billing currency (M-4.1).
- **Done when**: tests cover overage with and without rollover, rounding, a currency or tax mismatch
  being impossible, idempotency (running twice doesn't double-bill), link and unlink and lock
  behaviour, finalized invoices being untouched, and the M-4.3 opt-in.

### Step M-13.11 — Portal hours summary [opt-in per client]
- `clients.share_hours_in_portal` (Owner-only toggle, default off). When on, the portal shows a
  **"Your hours"** panel: hours included this cycle, hours used, and hours by category. It never shows
  rates, cost, margin, staff names, notes or entries from the "Internal" client.
- Monthly client reports (M-3.3) gain an optional "Hours this month" block under the same toggle, and
  it's frozen with the report.
- **Done when**: isolation tests cover a `ClientReviewer` seeing only their own client's summary and
  only when switched on, the payload holding no rates, names or notes, and a published report not
  changing when entries are edited later.

### Step M-13.12 — AI profitability brief [Conditional: M-7.1 done]
- An Owner-only **"Explain this month"** action on the profitability section. It drafts a short,
  plain-English brief: which clients moved, likely reasons (scope creep, format mix, approval delays
  from `ApprovalMetrics`) and suggested next steps (rescope, reprice at renewal, template a heavy
  format). It uses the existing `AiProvider` with budgets and logging through M-7.1.
- **Data minimisation:** the prompt only gets aggregated figures, with client names and categories.
  It never gets staff names, individual cost rates, notes or secrets. Raw prompts never reach the
  frontend or logs.
- The output is a draft the Owner can edit and save as an internal note. It never sends, emails or
  changes a retainer or invoice.
- **Done when**: a test asserts the prompt payload holds no staff names or per-person rates, the
  action is Owner-only and budget-checked, and no code path sends or applies the brief without a user action.

### Step M-13.13 — Imports, plan gating and data rights
- **CSV import** of time from Harvest, Toggl Track and Clockify exports (column mapping per source,
  people and clients matched by name or email with a review screen, `source = import`). This helps
  agencies switching tools. There's also a downloadable CSV template.
- **Gating:** a `TimeFeatures` contract backed by `Entitlements` (M-10.1), like `CampaignFeatures`,
  applying the M-13.1 decision. Logging and burn are never gated.
- **Data rights:** time entries, categories, rates and snapshots are included in workspace export and
  delete (M-10.6). Removing a staff member keeps their entries via `user_name_snapshot`. Entries are
  added to the GDPR processor record (I-16.3).
- **API:** read and create endpoints for time entries are added to M-12.1's API backlog, with an
  `time:write` token ability. Read-only MCP tools ("hours for client this month") are added to
  M-12.2. Cost and margin are never exposed outside Owner tokens.
- **Done when**: tests cover each importer with a fixture file (including unmatched rows held for
  review), gating per plan with logging never blocked, export including time data, and a removed
  user's entries still reporting under their name.

### Phase M-13 exit criteria
- A Designer can start a timer from the post editor, stop it from any page, and fill the rest of
  their week on the timesheet in under a minute.
- An Owner can open any client and see hours used against the retainer, pace, effective hourly rate
  and margin for this cycle and the last 12 months, and close a month that then never changes.
- Client health warns before a retainer runs over, and overage becomes a draft invoice line in one
  click without touching a finalized invoice.
- Org analytics shows profitability by client, campaign and content format, plus team utilisation
  with no ranking. Cost and margin are only ever visible to Owners (isolation tests on every surface,
  including scheduled reports, the portal and wrap reports).
- `time-tracking.md` and every touched module doc match the code (`sync-module-docs` reports no drift).

---

## Sequencing summary

| Order | Phase | Depends on |
|---|---|---|
| 1 | M-0 Decisions & groundwork | none |
| 2 | M-1 Production foundations | M-0 |
| 3 | M-2 Publishing parity + client connect | M-0.2, M-1.6 |
| 4 | M-3 Prove results | M-0.2 (metrics source), M-2.1 |
| 5 | M-4 Invoicing you can send | M-0.1 (adviser question raised) |
| 6 | M-5 Content workflow + Canva | M-1.4, M-2 |
| 7 | M-6 Approvals & client experience | M-2.9, M-5.3 (diffs), M-5.5 (previews) |
| 8 | M-7 AI control plane & edge | M-2.2 (overrides) |
| 9 | M-8 **Design-partner release** | M-1 to M-7 |
| 10 | M-9 Social inbox [Conditional] | M-8.3, M-0.2, M-7.1 |
| 11 | M-10 Sell it | M-7.1 (AI credits), M-8.3 (pricing check) |
| 12 | M-11 Public launch | everything above |
| 13 | M-12 After launch | M-11 |
| 14 | M-13 Time & profitability | M-4 (billing currency, retainer drafts), M-3.4 (health v2), M-5.4 ("My work"), M-10.1 (gating, M-13.13 only), M-7.1 (M-13.12 only) |

M-4 and M-7 barely touch the same files as their neighbours, so either can run in parallel with M-3
or M-5 if there's capacity. M-13 can run alongside M-12, or be pulled ahead of M-8 if the M-8.3 gate
ranks it in the top 5 (M-13.1). Everything else goes in order.

## Cross-cutting, apply throughout every phase

- **Role scoping:** `ClientReviewer` only ever sees their own client, never billing data (except their
  own invoices in the portal), and only posts from "Waiting on client" onwards. Every new surface
  (connect page, grid, reports, pay link, inbox, MCP, time and profitability) gets an isolation test.
  Staff cost rates and margin are Owner-only everywhere (M-13).
- **AI:** suggests and drafts only. It never publishes, approves or replies on its own. Secrets and raw
  prompts never reach the frontend, logs or MCP responses.
- **Money:** agencies' client payments (M-4.2) and Alima's own subscriptions (M-10.2) are separate
  Stripe integrations and never share keys, webhooks or tables.
- **Brand:** Cloud Cream workspace, Deep Teal frame, one hero accent per layout, Signal Orange
  decorative only, status always colour plus label (`StatusPill`), no raw hex values.
- **Security (spec §10)** and the **adapter-swap UUID regression test** from
  `graphy-development-steps.md` stay green throughout.
- **Out of scope (spec §13):** ads manager, Meta Business Suite automation, Planable sync, white-label
  billing.

## Decisions

_Filled in by Steps M-0.1 and M-8.3, and updated as phases land. Each entry: decision, who approved,
date (or "pending")._

### M-0.1 — Scope, pricing and open decisions (recorded 19 Oct 2026)

- **Scope** (recorded 19 Oct 2026; scope approval pending with pricing):
  - *In:* everything in "Gaps that block a sale". That means engagement analytics; self-serve
    subscriptions, trials and plan limits; X, YouTube Shorts, Threads, Pinterest and Google Business
    Profile, plus IG Stories; VAT/TRN invoicing; the client self-connect link; Canva, Google Drive and
    Dropbox import; bulk CSV scheduling; approval reminders, SLAs and staged approvers; scheduled
    client reports; card payment links; logo-first white-label. The social inbox is conditional
    (M-8.3), and time tracking and accounting sync come after launch (M-12).
  - *Out:* social listening, competitor benchmarking, "universal content", native mobile apps,
    business and creator workspaces, ads, UAE e-invoicing (revisit when an Accredited Service
    Provider is chosen), and everything in spec §13 (ads manager, Meta Business Suite automation,
    Planable sync, white-label billing).
- **Pricing**: **pending** Alex and Rimma's approval. This is a recommendation from PRD-001 §5:
  - Per-agency subscription, banded by **active client brands**, with seats included.
  - **Unlimited free client reviewers** that never count as seats.
  - Social profiles aren't metered beyond a fair-use limit of about 10 per brand. **Posts are never
    limited.**
  - Tiers, ex VAT:

    | Tier | GBP / AED per month | Brands | Seats |
    |---|---|---|---|
    | Studio | £99 / AED 449 | 6 | 5 |
    | Agency | £199 / AED 899 | 15 | 12 |
    | Agency Plus | £399 / AED 1,799 | 40 | 25 |
    | Group | from £799 / AED 3,699 | custom | custom |

  - Annual billing is 10 months for 12. 14-day trial with no card. No free plan.
  - `/pricing` stays behind its Pennant flag until this is approved (M-10.5).
  - *Margin input from M-0.2:* each org currently brings its own Upload-Post key, and we create one
    vendor profile per social account. See `publishing.md` › Vendor cost before M-10.1.
- **Gating rule**: **pending** (it follows the pricing approval).
  - Never gate the client-to-cash core: approvals, portal, invoicing, client health and publishing.
  - Gate the time-savers: AI campaign planner, wrap reports, campaign templates and comparison, saved
    and scheduled reports, invoices from campaigns, and the AI allowance.
  - White-label is included in Agency Plus and a £49 add-on elsewhere, and is only sold once built.
- **Group tier**: **pending** (it follows the pricing approval). It isn't listed until a real
  prospect needs it, because multi-org membership (I-12.3) isn't built and users have a single
  `org_id`. See M-12.5.
- **Arabic client experience**: **no** for now (Alex, 19 Oct 2026). M-6.8 is skipped, and right-to-left
  captions stay as built. Revisit at the M-8.3 gate with UAE design-partner feedback. An Arabic staff
  interface stays after launch.
- **Inbox go/no-go**: deferred to M-8.3 by design.
- **Tax adviser**: questions for M-4.1 (UK and UAE invoice contents) and M-10.2 (VAT on Alima's own
  subscriptions) are **open**. There is no answer yet.

### M-0.2 — Vendor audit outcomes (19 Oct 2026, implementation)

- Every MVP network publishes through Upload-Post. Analytics come from Upload-Post for all nine
  networks (Google Business is account-level only). Comments come from Upload-Post for seven
  networks, with Pinterest and Google Business deferred. No Phase 4 second adapter is needed, and no
  new Meta App Review scopes are needed while Upload-Post is the source.
- The audit used public docs and the pricing page, not a sandbox account. A live sandbox check of the
  analytics and comment fields is required at the start of M-3.1 and M-9.1.
- The Meta developer dashboard's review status couldn't be checked here. Alex to confirm.

- **M-1.3 (S3) deferred** (20 Oct 2026, Alex): the bucket isn't set up yet. Phase M-1's other steps are done; uploads stay on the private local disk.
- **CI on PHP 8.5** (20 Oct 2026, implementation): the lock needs PHP ≥ 8.4.1 (Symfony 8), so the 8.3 job couldn't install; 8.5 matches local Herd.
- **Phase M-2 run ahead of M-0/M-1** (20 Oct 2026, requested by Alex): M-0.2's vendor audit was done
  from Upload-Post's public docs (capability table in `publishing.md`), not a sandbox; M-1.6
  (editor decomposition) is still open, so the new editor features live in their own components.
- **Bluesky deferred** (20 Oct 2026, implementation): the MVP note made it optional; not built.
- **New networks default on** behind `PUBLISH_ROLLOUT_*` flags (20 Oct 2026, implementation),
  matching the Step 1.7 pattern; switch one off if the live sandbox disagrees with the docs.
- **Alt text is per image, not per channel** (20 Oct 2026, implementation) — one description goes to
  every channel that accepts alt text.
- **Vendor-held edits cancel and re-submit** rather than PATCH (20 Oct 2026, implementation): one
  path for time, content and media changes; Upload-Post refunds cancelled jobs' credits.

- **Phase M-3 (21 Oct 2026, implementation):**
  - Engagement comes through its own `EngagementSource` contract rather than an optional
    `PublishAdapter::metrics()` (Phase 12's sketch), so a metrics-only fallback (Meta Graph) can be
    bound without touching publishing.
  - Field names follow Upload-Post's docs; the live sandbox check from M-0.2 is still due (see
    `analytics.md` › Known gaps).
  - "Overdue approval" in health v2 uses a fixed 48 hours (`config/clients.php`) until M-6.5's
    per-organization SLA exists. Brand Brain gaps are advisory (listed, never amber on their own).
  - Monthly client reports default to **review** (a draft for the team to annotate), not automatic;
    recipients are the client's portal reviewers plus its approval email until client contacts
    (M-6.1) exist. No invoice-line "work summary" — that's billing data.
  - Monthly reports aren't plan-gated yet (gating waits on M-10.1).

### Phase M-4 (24 Oct 2026, implementation)

- **M-4.1 scope kept MVP-narrow** (approved by Alex, 24 Oct 2026): VAT/TRN, a per-org editable
  tax-rate list, per-item + invoice-level tax, per-client billing currency. Partial payments, credit
  notes, discounts and configurable invoice-number formats from I-11.1 are **deferred to M-12**.
- **Tax adviser sign-off still open:** the exact UK/UAE tax-invoice contents aren't confirmed yet.
  The feature ships with rates/labels as editable per-org data so the final wording is a config
  change; M-4.1 is "feature-complete, sign-off pending" and isn't ticked as fully Done until the
  adviser answers (recorded in `billing.md`).
- **`amount` stays the grand total due** (= subtotal + tax); `subtotal`/`tax_total`/`tax_breakdown`
  are snapshotted, so the ledger, outstanding totals and dashboard revenue chart are unchanged.
- **A `pending_confirmation` invoice reverts to `sent` before it can be voided** (M-4.2 recommendation
  taken; recorded in `billing.md`).
- **Recurring retainers draft only, never auto-send** (M-4.3); the job is daily and idempotent per
  client + period.
- The *invoice → send → client marks paid → org confirms → paid* Playwright journey (phase exit
  criterion) is still to be added in M-11.2 alongside the other critical journeys.

### M-8.3 — Inbox decision (recorded 24 Oct 2026)

- **Inbox: go** (Alex, 24 Oct 2026). M-9 (the social inbox) proceeds. This supersedes the earlier
  "deferred to M-8.3 by design" note under M-0.1. The go/no-go was confirmed directly rather than
  derived from the two-week partner trial, which hasn't run yet.
- **The rest of the M-8.3 gate is on hold** (Alex, 24 Oct 2026): the **Arabic** re-confirmation,
  the **pricing** questions (PRD-001 §6.6) and the **top-5 friction points** are not recorded yet —
  they wait on real design-partner usage. The usage view and feedback inbox built in M-8.2 are the
  instruments for that review.
- **M-8.1 infrastructure deferred** (24 Oct 2026): S3 stays on hold (per the 20 Oct decision), and
  the production restore drill + Meta app-review confirmation are launch/ops tasks tracked outside
  this code change. Only the `is_design_partner` flag shipped from M-8.1.

## Launch log

_Filled in by Step M-11.3._

## Changes in v2.1 (22 Oct 2026)

- Added **Phase M-13 — Time & profitability**, a new module (`time-tracking.md`) that replaces the
  light M-12.4 step. It covers timers, timesheets, retainer burn and rollover, effective-dated cost
  rates, month close with frozen snapshots, client, campaign and content-format profitability, team
  utilisation, overage to draft invoices, an opt-in portal hours summary, an AI brief (drafts only)
  and imports from Harvest, Toggl and Clockify. Gap #11 now points at M-13.

## Changes from v1 (18 Oct 2026)

- Reconciled with PRD-001. Engagement analytics moved up to M-3 (PRD #1). Added VAT/TRN invoicing
  and payment links (M-4), the client self-connect link (M-2.8), and a design-partner release with an
  inbox decision gate (M-8). The inbox is now conditional (M-9), Canva moved forward to M-5.9, and
  light time tracking was added (M-12.4).
- Pricing replaced with PRD-001's model: per agency, banded by brands, free unlimited reviewers,
  Studio/Agency/Agency Plus/Group, GBP and AED, a no-card trial, design-partner and founding coupons.
- Wired in Campaigns v2 (all C- steps done): the hub reuses the campaign board, approvals route C-5.2
  and C-2.3 through staged approvals, engagement turns on campaign KPIs, and entitlements back
  `CampaignFeatures`.
- Corrected two claims from PRD-001: the Arabic **interface** isn't built (only right-to-left
  captions; now M-6.8, conditional), and multi-agency accounts aren't built (Group tier is
  conditional on I-12.3, M-12.5).
- Google Business Profile moved up the network order. Accounting sync order is now Xero → Zoho Books → QuickBooks.
