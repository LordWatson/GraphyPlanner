# Graphy — Outstanding Work to Go to Market

Everything still open between today's build and a paid public launch, in the order that earns the
most for the least work. Written after the **November 2026 competitor check**, which re-ran the
October review in `.junie/plans/business-competitor-gap.md` against vendors' own pricing pages and
found two things had changed:

1. **MCP is no longer a differentiator.** Buffer and Metricool both ship official MCP servers on
   their free plans, Planable lists MCP on every tier including Basic, and Hootsuite has
   product-level MCPs. Our MCP server (`.junie/modules/mcp.md`) is still far deeper — org-scoped,
   capability-gated, rate-limited, audited, per-org kill switch, checked-in security review — but
   "we have agent access and they don't" is dead as a pitch. The claim becomes *depth*, not
   existence (GTM-7.4).
2. **Planoly now bundles most of CG-3/CG-4 at $14/month, on every plan**: grid planner, link in bio,
   zero-fee selling of downloads/services/bookings, physical merch, Instagram auto-DMs and a CRM.
   Creator Pro at £29/$37 is 2.6x that price for a narrower creator feature set. This is a
   positioning problem, not a feature problem (GTM-0.2).

**What this plan is not.** It is not feature parity with Metricool or Planoly — we lose that on
price and on brand recognition. It is the work needed to sell what we already have, finish the few
things that shipped half-done, and close the three gaps that genuinely disqualify us from a sale
(no white label, no benchmarking, no email).

Read along:
- `.junie/plans/business-competitor-gap.md` — the CG phases. Steps here that say "**Closes CG-x.y**"
  build that step and mark **both** done.
- `.junie/plans/business-workspaces.md` — the B phases and the resolved-decisions log.
- `.junie/AGENTS.md` — the coding principles every step below is bound by.

---

## 1. Principles

1. **Sell before you build.** GTM-0 is pricing and positioning decisions, no code. The agency tier
   is already the strongest product we have and currently costs £0 (`config/plans.php`
   `agency_standard`: `public => false`, `amount => 0`, every gate `true`). Nothing in this plan
   earns more than publishing a price for it.
2. **Reuse the seams, never fork the domain.** Every step below composes existing contracts
   (`ProfileFeedSource`, `EngagementSource`, `PaymentProvider`, `AssetStorage`, `Entitlements`) or
   adds one alongside them. No step introduces a parallel model of a thing we already model.
3. **Every new capability is an `Entitlements` gate.** A new `Feature` case, a default in
   `$gateDefaults`/`$limitDefaults`, a value per plan, and one test per gate. A feature that cannot
   be sold per plan is not finished.
4. **Cheap versions of table stakes.** We build the *credible* version of listening and ads
   reporting (GTM-4, GTM-5), not the real one. Both are scheduled reads over data we already have
   access to, surfaced in the report the client already receives.
5. **Thin controllers, Actions/Services, transactions, logging, tests, module docs** — as
   `.junie/AGENTS.md` requires, on every step, with no exceptions for "launch pressure".

### 1.1 Non-goals (for this plan)

- **Real social listening.** No mention-volume crawling, no sentiment-over-the-web, no Brandwatch.
  GTM-4 tracks named public profiles and (optionally) named keywords. Nothing more.
- **Paid ads management.** GTM-5 is read-only reporting. We never create, edit or spend.
- **Merch, courses and memberships** (CG-7). Printful and Stan own these, the margin is thin and the
  surface is large. Explicitly deferred in GTM-8.
- **Native iOS/Android.** GTM-6 is a Trusted Web Activity wrapper of the existing PWA. Nothing more.
- **Instagram auto-DM** (CG-6.4). Blocked on our own Meta app (Phase 5). Deferred in GTM-8.

---

## 2. Prerequisites

- CG-1 through CG-5 are done (switched-on surfaces, plans and payments, bio pages, creator store,
  brand deals). Verify with the full suite green before starting.
- Stripe: the Cashier platform account exists and `STRIPE_PRICE_*` env vars are wired for
  `business_pro` and `creator_pro`. GTM-0.1 adds the agency price IDs to the same pattern.
- `MAIL_MAILER=resend` in the launch environment. GTM-2 sends real email and `log` will silently
  swallow it.
- A decision on GTM-0.3 (tax/VAT and entity) **before** the store takes money from a real customer.
  This is the one hard blocker on launch in the whole plan.

---

## Phase GTM-0 — Decide and price [MVP]

No code. Three decisions, each recorded in a module doc, that unblock everything after.

### Step GTM-0.1 — Publish an agency price [MVP]

Today agencies get every gate, unlimited limits, for £0, on a non-public plan. Cloud Campaign
charges $199/mo (Team, standard white label) and $299/mo (Agency, advanced white label) for less
product than we already ship. This step is a config change and a pricing page row.

- Decide the agency tier shape. Starting proposal from the November review (confirm or change):

  | Plan | Price | Brands | Seats | Notes |
  |---|---|---|---|---|
  | `agency_starter` | £49 / $59 | 5 | 3 | No white label |
  | `agency_standard` | £99 / $119 | 15 | 10 | White label (GTM-3.1), benchmarks (GTM-4) |
  | `agency_scale` | £199 / $239 | 50 | unlimited | Adds ads reporting (GTM-5), priority support |

- In `config/plans.php`: add the new plan keys with real `amount`s and `STRIPE_PRICE_AGENCY_*` env
  vars following the existing `business_pro` pattern, set `'public' => true`, set per-plan
  `Feature::Brands` / `Feature::TeamSeats` / `Feature::SocialAccounts` limits instead of the
  `$limitDefaults` nulls, and keep `agency_standard` as `plans.defaults.agency` so existing orgs
  land on the middle tier.
- **Grandfather existing agency orgs.** A data migration (or a super-admin plan override via the
  CG-2.6 controls in `routes/web.php`) that keeps every current agency org on unlimited limits. No
  existing customer loses capacity to this change.
- Add the agency column(s) to the `MarketingController::pricing` page (`/pricing`), which generates
  from config — check the segment tabs render three segments, not two.
- **Done when**: `/pricing` shows agency plans with real prices; `ConfigEntitlements` enforces the
  new brand/seat limits with a Feature test per limit; a test asserts every existing agency org is
  unlimited after the migration; `plans.md` records the final table and that §5.1 of the CG plan's
  open questions is resolved.

### Step GTM-0.2 — Reposition Creator Pro [MVP]

At £29/$37 against Planoly's $14 (which has bio, store, grid and auto-DM), the store and the bio
page cannot be the headline. What Planoly, Stan and Beacons do **not** have is the deals tracker,
publish-blocking disclosure and a media kit built from real metrics.

- Pick one of two routes and record it:
  - **(a) Hold £29 and change the story.** Creator Pro leads on "get paid, stay legal": deals
    tracker, ASA/FTC disclosure enforcement, media kit with real data and sponsor share-preview.
    Store and bio become supporting bullets, not the headline.
  - **(b) Cut to £19/$24 and add email.** Matches the mid-market and makes GTM-2 the thing that
    justifies the price, as it does at Stan ($29 with email marketing).
- Rewrite `creator_pro.tagline` and `highlights` in `config/plans.php` to match the chosen route.
  The current tagline ("a storefront that pays you 100%") is the Planoly-overlapping claim.
- Update the "How we compare" table from CG-2.7 with November figures, or delete it. An out-of-date
  comparison table is worse than none.
- **Done when**: the route, the price and the new highlights are in `plans.md`, the pricing page
  reflects them, and any superseded figures in `business-competitor-gap.md` are corrected.

### Step GTM-0.3 — Payments, tax and entity [MVP] — **Closes CG-0.2**

The store is live and CG-0.2 was never answered. This is the launch blocker.

- Decide and record: (1) which legal entity (UK or UAE) holds the Stripe platform account for
  Cashier and for Connect; (2) Connect account type (Express recommended); (3) sales tax/VAT on
  creators' sales — creator's responsibility with Stripe Tax on their connected account
  (Planoly/Stan model) or a merchant-of-record partner — **get an accountant's view on UK/EU VAT for
  digital goods before choosing**; (4) launch currencies for creator sales; (5) whether the free plan
  can sell, and at what fee.
- If free-plan selling is allowed, add the fee to `config/plans.php` as a plan value (not a constant)
  and enforce it in the `PaymentProvider` implementation, with a test per plan fee.
- **Done when**: the five answers are in `creator-store.md` under resolved decisions, CG-0.2 is
  marked done, and CG-4's steps are updated where an answer changes them.

---

## Phase GTM-1 — Finish what shipped half-done [MVP]

Three known loose ends from CG-4 and CG-5, each flagged in its own module doc.

### Step GTM-1.1 — S3 for digital downloads [MVP] — **Closes the CG-4.3 gap**

- Point the download path at the configured S3 disk through the existing `AssetStorage` contract
  rather than a new storage path. Signed, expiring URLs; the object is never public.
- Enforce: per-order download limits and link expiry already modelled in CG-4.3, now on real signed
  S3 URLs. Virus-scan the upload through the existing `VirusScanner` contract before the product can
  be published.
- Log the issue of every download URL with `order_id` and `product_id` in context. Never log the
  signed URL itself.
- **Done when**: a feature test covers a buyer downloading within the limit, a refused download past
  expiry/limit, and an unscanned file being unpublishable; `creator-store.md` drops the "S3 still
  pending" note.

### Step GTM-1.2 — Stripe invoice from a brand deal [V1] — **Closes the CG-5.2 gap**

- An Action (`App\Actions\Deals\IssueDealInvoiceAction`) that turns a `Deal` into an invoice. Reuse
  the existing `Invoice` model and PDF/reminder pipeline from `billing.md` — a brand deal billing a
  sponsor is the same shape as an agency billing a client. Do **not** add a second invoice model.
- Wrap in `DB::transaction()` (invoice + line items + deal status + activity log). Log issue and
  failure with `deal_id` and `invoice_id`.
- **Done when**: tests cover issuing from a deal, the deal status moving once and only once, and a
  failed issue leaving no partial invoice; `brand-deals.md` drops the "no Stripe invoice" note.

### Step GTM-1.3 — Pre-launch loose-ends sweep [MVP]

- Grep the module docs for "pending", "not built", "TODO", "known gap" and triage every hit into:
  fix now, record as a known limitation on the pricing/FAQ page, or delete the feature.
- Specifically confirm or close: CG-3.2's AI bio draft (Stretch, unbuilt — decide whether the editor
  still advertises it), and whether the comment inbox is on the Free plan (CG open question §5.6 —
  Buffer includes it on every plan).
- **Done when**: no module doc claims a capability the code doesn't have, and the decisions are
  recorded in the relevant docs.

---

## Phase GTM-2 — Audience and email [V1] — **Closes CG-6.1–6.3**

The largest creator-side gap, fully specified already, no platform dependency. A store with no email
list is half a business, and it is what Stan charges $29 for. The `Feature::EmailAutomation` gate
already exists in `App\Enums\Feature` — this phase gives it something to gate.

### Step GTM-2.1 — Contacts and capture [V1]

- `audience_contacts` table: `org_id`, `client_id`, `email`, `name`, `source`
  (`order|bio_capture|import`), `consent_at`, `unsubscribed_at`, timestamps. Unique on
  `(client_id, email)`. Keep the SQL MySQL-8-and-SQLite compatible (tests run on in-memory SQLite).
- An `email_capture` block type for the bio page. The CG-3.1 block model takes new types without a
  migration — use that, don't migrate. **Double opt-in**: the block creates the contact with
  `consent_at = null` and sends a confirmation email; only the confirmation link sets `consent_at`.
- Buyers from the store are created with `source = order` and `consent_at` set only if the checkout
  carried an explicit marketing opt-in — a purchase is not consent to marketing.
- CSV import that sends an opt-in email and **never** sets `consent_at` on import.
- A contacts list in the business shell, behind `Feature::EmailAutomation`.
- Cover contacts in the B-13.2 export/deletion paths (GDPR) in the same change.
- **Done when**: tests cover double opt-in (unconfirmed contact is never mailable), unsubscribe,
  import never marking consent, checkout opt-in both ways, and export/deletion including contacts.

### Step GTM-2.2 — Automated emails [V1]

- Three triggers: purchase follow-up, abandoned checkout (from Stripe
  `checkout.session.expired`), and welcome-on-confirm. Queued jobs, **idempotent per contact +
  trigger** (a unique sent-record row, not a cache key).
- Sent through Resend with the brand's styling and an unsubscribe link in every message. Follow the
  existing `app/Mail/*Mail.php` conventions.
- Cancel the abandoned-checkout email if the order completes first.
- Log each send with `contact_id` and trigger. Never log the email body.
- **Done when**: tests cover each trigger firing exactly once, unsubscribed and unconfirmed contacts
  skipped, the abandoned email cancelled on completion, and the entitlement gate refusing sends on a
  plan without `EmailAutomation`.

### Step GTM-2.3 — Broadcasts with AI drafts [V1]

- A one-off email to a segment (all confirmed, buyers of product X). "Draft with AI" fills it from
  the Brand Brain through the existing `WritingAssistant`/`AiProvider` seam, metered against
  `Feature::AiTextCredits`.
- **The owner sends. AI never sends.** The send endpoint requires an authenticated human request;
  no job may originate a broadcast.
- **Done when**: tests cover segment selection, metering, a human-only send path, and that an
  unsubscribed contact is excluded from every segment.

---

## Phase GTM-3 — White label and custom domains [V1]

Opens the reseller-agency segment (a hard disqualifier today) and the paid-domain upsell that Stan,
Beacons and Linktree all charge for. Two new `Feature` gates: `WhiteLabel`, `CustomDomain`.

### Step GTM-3.1 — Agency white label [V1]

- Per-org branding: logo, accent colour, sender name and reply-to, custom footer. Store on
  `organizations` (it already carries settings columns) — no new model.
- Apply it to the three surfaces a client actually sees: the **client portal** shell
  (`client-portal.md`), the **client report** PDF/page (`analytics.md`, M-3.3), and **outbound mail**
  (`ClientReportPublishedMail`, `ClientReviewRequestedMail`, `InvoiceSentMail` and the rest of
  `app/Mail/`). Resolve branding once in a Service, not per-template.
- Respect the brand guidelines' constraint that Signal Orange is ours, not a client's: a white-label
  accent never overrides our own product chrome, only the client-facing surfaces listed above.
- Gate behind `Feature::WhiteLabel`, true from `agency_standard` up.
- **Done when**: tests assert the portal, the report and one email each render org branding when the
  gate is on and our branding when it's off; `settings.md` and `client-portal.md` updated.

### Step GTM-3.2 — Custom domains for bio pages [V1]

- `bio_page_domains`: `bio_page_id`, `hostname` (unique), `verification_token`, `verified_at`,
  `ssl_status`. A CNAME-verification Action plus a nightly re-verification job that flags a domain
  that stops resolving.
- Serve the existing `@{handle}` bio and store routes on a verified hostname: resolve the `BioPage`
  by hostname when the request host isn't our app domain, before the `@{handle}` route matches. The
  handle namespace stays the canonical address and keeps working.
- Gate behind `Feature::CustomDomain` (Creator Pro and up). Document the DNS/TLS operational
  requirement in `ops.md` — this is the one step with real infrastructure surface.
- **Done when**: tests cover verification success and failure, an unverified domain never serving,
  hostname resolution preferring the verified domain and the handle still resolving, and the gate.
  Answers CG open question §5.5 (handle namespace).

---

## Phase GTM-4 — Competitor benchmarking [V1]

The credible version of listening. Metricool sells 100 tracked competitor profiles at €16/month, and
Gartner's new Magic Quadrant is named "Social Media Management **and** Listening" — having nothing
here loses RFPs on the category definition alone. We already have the seam: `ProfileFeedSource`
reads a public Instagram profile for the grid planner, and `social_account_metrics` is the storage
pattern.

### Step GTM-4.1 — Tracked profiles and nightly readings [V1]

- `tracked_profiles`: `org_id`, `client_id`, `platform`, `handle`, `label`, `added_by`, timestamps.
  `tracked_profile_metrics` mirroring the `social_account_metrics` shape: followers, post count,
  average engagement, `captured_on` (unique per profile per day, so a re-run upserts).
- A `CompetitorProfileSource` contract alongside `ProfileFeedSource` — public profile metrics for a
  handle we do **not** own — with a null implementation for tests and for platforms that refuse.
  Implementations never throw to the page, matching `ProfileFeedSource`'s documented contract.
- A nightly queued job per org, rate-limited and logged with `tracked_profile_id`. A platform that
  blocks or rate-limits us records a gap, never a crash and never a fabricated zero.
- Limit tracked profiles with a new `Feature::TrackedProfiles` limit per plan.
- **Done when**: tests cover the daily upsert, a refusing source leaving a gap, the per-plan limit,
  and org scoping (one org never sees another's tracked profiles).

### Step GTM-4.2 — Benchmark view and report block [V1]

- A benchmarks panel on the client's analytics page: our account against its tracked profiles over
  the selected window, with the honest gaps shown as gaps.
- A benchmark block in the M-3.3 client report, and a plain-English takeaway through the existing
  `InsightsSummarizer` seam ("you posted less than three of four tracked accounts this month").
- Gate behind `Feature::CompetitorBenchmarks`.
- **Done when**: tests cover the panel with and without data, the report block, the gate, and that a
  window with no readings renders empty rather than zero.

### Step GTM-4.3 — Keyword mentions [Stretch]

- Named keywords per client, matched against the sources we already ingest (comments via
  `CommentSource`, trends via `TrendSource`). **No new crawling.** If that yields too little to be
  useful, delete this step rather than buying a listening vendor.
- **Done when**: shipped with tests, or recorded as dropped with the reason in `analytics.md`.

---

## Phase GTM-5 — Paid ads reporting, read-only [V1]

Agencies report on ad spend monthly. Metricool manages ads, Later Scale shows Meta Ads analytics,
Cloud Campaign sells paid social reporting on its Agency tier. Reporting is ~10% of the work of
management and most of the value.

### Step GTM-5.1 — `AdsSource` contract and metrics [V1]

- `App\Contracts\AdsSource`, modelled directly on `EngagementSource` (`key()`,
  `accountMetrics()`): spend, impressions, clicks, conversions, CPC, CPA per ad account per day.
  Bind a Meta implementation in `AppServiceProvider` plus a null implementation.
- `ad_accounts` (`org_id`, `client_id`, `platform`, `external_account_id`, `connection_status`) and
  `ad_account_metrics` (daily, unique on `(ad_account_id, captured_on)`), following the
  `social_accounts` / `social_account_metrics` pattern exactly.
- **Read-only by construction**: the contract exposes no write method. Document that in its PHPDoc so
  no later step quietly adds spend control.
- **Done when**: contract tests with a fake source cover the daily upsert, a disconnected account, a
  refusing API, and that no code path can create or edit a campaign at the platform.

### Step GTM-5.2 — Ads in the report and the dashboard [V1]

- An ads section in the M-3.3 client report and on the client dashboard: spend, results, trend, and
  organic-vs-paid side by side.
- Gate behind `Feature::AdsReporting` (agency top tier per GTM-0.1).
- **Done when**: tests cover the section with data, with a disconnected account, and the gate;
  `analytics.md` documents the new tables and their source.

---

## Phase GTM-6 — Mobile reach [V1] — **Closes CG-8.1**

Planoly's free tier is **mobile-app-only** (10 uploads/month). An app-store listing is how creators
enter this category, and we have a PWA and working push already (CG-1.3).

### Step GTM-6.1 — Trusted Web Activity to Google Play [V1]

- Wrap the existing PWA as a TWA: `assetlinks.json` on the app domain, a minimal Android project,
  Play listing (icons, screenshots, privacy policy, data-safety declaration).
- Verify push notifications arrive in the installed TWA — that is the whole point of shipping it.
- Record the decision and the CG-1.3 delivery evidence in `business-workspaces.md`, which closes
  CG-8.1's "decide between PWA, TWA or native".
- **Done when**: the app is listed, push is confirmed on a real device, and the decision log names
  TWA as the answer and native as explicitly declined.

---

## Phase GTM-7 — Launch mechanics [MVP/V1]

### Step GTM-7.1 — Funnel metrics [V1] — **Closes CG-9.1 / B-12.4**

- Trial start → paid conversion, time to first published post, % of workspaces with a published bio
  page, store GMV (from `orders`, never from the Stripe balance), churn by plan, and — new — agency
  seat and brand utilisation against the GTM-0.1 limits, so the next price change has evidence.
- **Done when**: B-12.4's done-when plus a fixture per new metric, visible in the super-admin console.

### Step GTM-7.2 — Quarterly competitor review [V1] — **Closes CG-9.2**

- An artisan command plus scheduled super-admin notification each quarter to re-check competitor
  pricing and refresh the gap docs. Seed it with the November 2026 findings at the top of this file
  and set the first review date.
- **Done when**: the command and its test exist and the first review date is in
  `business-workspaces.md`.

### Step GTM-7.3 — Distribution: comparison pages and affiliates [MVP]

Worth more than any feature in this plan and cheaper than all of them. Every "best social media tool
2026" result is affiliate-monetised content — that is the distribution channel in this category, and
we are absent from it.

- Honest comparison pages under the existing `MarketingController`: Graphy vs Planoly, vs Metricool,
  vs Cloud Campaign, vs Later. Figures dated and sourced, with a "last checked" date wired to
  GTM-7.2 so they can't silently rot. Never claim a competitor lacks something without checking.
- An affiliate/referral programme: a tracked referral code on signup, a payout record, and a simple
  partner page. Reuse the Cashier customer record for attribution rather than modelling a new one.
- **Done when**: the pages are live with dated figures, a referral attributes end-to-end in a
  feature test, and `marketing.md` documents both.

### Step GTM-7.4 — Reposition MCP on depth [V1]

- Rewrite the MCP marketing claim now that Buffer, Metricool, Planable and Hootsuite all have one:
  lead on org-scoped isolation, capability scopes, per-connection rate limits, the audit trail, the
  two master switches and the checked-in security review — the things a buyer's security review asks
  about and the others' single-user connectors don't answer.
- **Done when**: the claim is live on the marketing site and `mcp.md` records that MCP is now
  table stakes, with depth as the differentiator.

---

## Phase GTM-8 — Explicitly deferred [Stretch]

Recorded so they stop being re-litigated. Do not build these before launch.

- **CG-7.1 merch / CG-7.2 courses and memberships.** Printful and Stan own these. Thin margin, large
  surface (fulfilment, refunds revoking access, renewals). Revisit only if store GMV from GTM-7.1
  shows creators asking for it.
- **CG-6.4 Instagram auto-DM.** Blocked on our own Meta app (Phase 5). Pull Phase 5 forward only if
  something else needs it — auto-DM alone doesn't justify it, even though Planoly bundles it at $14.
- **Real social listening.** See §1.1.
- **Native iOS/Android.** GTM-6.1 is the answer.

---

## 3. Sequencing and the launch cut

```
GTM-0 (decide & price) ──► GTM-1 (finish half-done) ──► LAUNCH
                                │
                                ├──► GTM-7.3 (distribution)   ← start in parallel, long lead time
                                ├──► GTM-2 (email)       ──┐
                                ├──► GTM-3 (white label) ──┼──► agency + creator upsells
                                ├──► GTM-4 (benchmarks)  ──┘
                                ├──► GTM-5 (ads reporting)
                                └──► GTM-6 (Play listing)
```

**Launch blockers** (nothing ships publicly without these): GTM-0.1, GTM-0.2, GTM-0.3, GTM-1.1,
GTM-1.3.

**First wave after launch**, in this order — GTM-2 (biggest creator gap, fully specified), GTM-3.1
(opens reseller agencies), GTM-4 (category table stakes), GTM-7.1/7.2.

**Second wave**: GTM-3.2, GTM-5, GTM-6.

**Start now regardless**: GTM-7.3. Comparison pages and an affiliate programme take weeks to earn
anything, so the clock should start before launch, not after.

Rough sizing: GTM-0 is decisions plus a config change (small). GTM-1 is small. GTM-2 is medium and
fully specified. GTM-3.1 is medium; GTM-3.2 carries real infrastructure risk (DNS/TLS) — give it a
dedicated review. GTM-4 is medium and mostly composition over `ProfileFeedSource`. GTM-5 is medium.
GTM-6 is small but has an unpredictable Play review queue, so start it early.

Give GTM-1.1 (signed URLs), GTM-2.1 (consent) and GTM-3.2 (hostname routing) a pass with
`graphy-convention-reviewer` before merge, and run the full agency suite after GTM-0.1 and GTM-3.1 —
both change behaviour for every existing org.

---

## 4. Module docs touched

- `plans.md` — GTM-0.1, GTM-0.2, and every new `Feature` gate/limit in GTM-3, GTM-4, GTM-5.
- `creator-store.md` — GTM-0.3, GTM-1.1.
- `brand-deals.md` — GTM-1.2.
- `bio-pages.md` — GTM-2.1 (capture block), GTM-3.2 (domains).
- `analytics.md` — GTM-4.2, GTM-4.3, GTM-5.2, GTM-7.1.
- `client-portal.md`, `settings.md` — GTM-3.1.
- `ops.md` — GTM-3.2 (DNS/TLS), GTM-4.1 (nightly jobs).
- `business-workspaces.md` — GTM-6.1, GTM-7.2 decision logs.
- `marketing.md` — GTM-7.3.
- `mcp.md` — GTM-7.4.
- **New**: `audience.md` (GTM-2), `benchmarks.md` (GTM-4), `ads.md` (GTM-5). Each needs its row in
  `.junie/modules/README.md` **in the same change** — `tests/Unit/ModuleDocsTest.php` fails
  otherwise.

---

## 5. Open questions

1. **Agency tiers.** Three tiers as proposed in GTM-0.1, or one published price? And do existing
   agency orgs get grandfathered forever or for a fixed term?
2. **Creator Pro.** Route (a) hold £29 and lead on deals/compliance, or route (b) cut to £19 and let
   email carry the price?
3. **Tax and VAT** (GTM-0.3) — still the launch blocker. Needs an accountant, not a decision in here.
4. **Free plan selling** — allowed with a fee, or paid plans only?
5. **Inbox on Free** — Buffer includes it on every plan. Include, or use it to drive upgrades?
6. **Ads platforms** — Meta only at first, or Meta + TikTok? Meta alone covers most agency reporting.
7. **Benchmark data sourcing** — which platforms can we legitimately read public profile metrics from
   under current API terms? This decides whether GTM-4 is one platform or four, and it is worth
   checking before GTM-4.1 starts rather than after.

---

## 6. Exit criteria for this plan

- An agency can see a price, pay it self-serve, and hit enforced brand/seat limits — and every
  existing agency org is unaffected.
- A creator's plan page and the pricing site tell a story that doesn't collapse the moment the
  visitor opens Planoly's pricing page in the next tab.
- Nothing in the store takes money before the tax/VAT and entity questions are answered in writing.
- No module doc claims a capability the code doesn't have.
- A creator can capture an email with double opt-in, get automated purchase and abandoned-checkout
  emails, and send a human-initiated broadcast — all gated by `Feature::EmailAutomation`.
- An agency can put its own brand on the portal, the report and outbound mail, and a creator can
  serve their bio page on their own verified domain.
- A client report can show tracked-competitor benchmarks and, on the top tier, paid ads results —
  with honest gaps where the platform gave us nothing.
- The product is listed on Google Play, push arrives on a real device, and the quarterly competitor
  review is scheduled with its first date set.
