# Graphy — Outstanding Work to Go to Market

Everything still open between today's build and a paid public launch, in the order that earns the
most for the least work. Written after the **November 2026 competitor check**, which re-ran the
October review in `.junie/plans/business-competitor-gap.md` against vendors' own pricing pages and
found two things had changed:

1. **MCP is no longer a differentiator.** Buffer and Metricool both ship official MCP servers on
   their free plans, Planable lists MCP on every tier including Basic, and Hootsuite has
   product-level MCPs. Our MCP server (`.junie/modules/mcp.md`) is still far deeper — org-scoped,
   capability-gated, rate-limited, audited, per-org kill switch, checked-in security review — but
   "we have agent access and they don't" is dead as a pitch. The claim becomes _depth_, not
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
4. **Cheap versions of table stakes.** We build the _credible_ version of listening and ads
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

## Phase GTM-0 — Decide and price [MVP] ⚠️ Done except GTM-0.3 (2026-11-07)

No code. Three decisions, each recorded in a module doc, that unblock everything after.

**Decided 2026-11-07:** three agency tiers at £49/£99/£199 (GTM-0.1); Creator Pro holds £29 and
leads on deals + compliance, route (a) (GTM-0.2); existing agency orgs grandfathered for 12 months
then migrated. **GTM-0.3 remains open** and is the one launch blocker: the five tax/entity questions
are recorded as a blocker in `creator-store.md` and need an accountant, not a developer.

### Step GTM-0.1 — Publish an agency price [MVP] ✅ Done

Today agencies get every gate, unlimited limits, for £0, on a non-public plan. Cloud Campaign
charges $199/mo (Team, standard white label) and $299/mo (Agency, advanced white label) for less
product than we already ship. This step is a config change and a pricing page row.

- Decide the agency tier shape. Starting proposal from the November review (confirm or change):

    | Plan              | Price       | Brands | Seats     | Notes                                        |
    | ----------------- | ----------- | ------ | --------- | -------------------------------------------- |
    | `agency_starter`  | £49 / $59   | 5      | 3         | No white label                               |
    | `agency_standard` | £99 / $119  | 15     | 10        | White label (GTM-3.1), benchmarks (GTM-4)    |
    | `agency_scale`    | £199 / $239 | 50     | unlimited | Adds ads reporting (GTM-5), priority support |

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
- **Built 2026-11-07.** Tiers as the table above. `agency_legacy` (non-public, unlimited) holds the
  grandfathered orgs; migration `2026_11_20_100000_grandfather_existing_agency_plans` moved every
  pre-existing agency org onto it with `plan_grandfathered_until` 12 months out, and
  `plans:end-grandfather` (daily, `EndPlanGrandfatherAction`) ends it on the day. The agency default
  moved to `agency_starter`, and `suggestUpgrade()` now keeps suggestions inside a segment. The
  pricing table is grouped by segment (it rendered one flat 3-column grid before, which six plans
  broke). AI credits left unlimited on every agency tier — logged as an open cost question in
  `plans.md`, not a decision.

### Step GTM-0.2 — Reposition Creator Pro [MVP] ✅ Done

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
- **Built 2026-11-07.** Route (a). New tagline ("Get paid for your work, and stay on the right side
  of the rules") and highlights leading on the deals tracker, publish-blocking disclosure and the
  real-numbers media kit; store and bio demoted to a supporting bullet. The CG-2.7 "How we compare"
  table turned out never to have been built, so there was nothing to refresh — GTM-7.3 covers it.

### Step GTM-0.3 — Payments, tax and entity [MVP] — **Closes CG-0.2** 🚩 OPEN — launch blocker

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
- **Status 2026-11-07: open, and the only thing blocking launch.** The five questions are written
  into `creator-store.md` as a flagged blocker with what each one needs. Answering 1–4 needs an
  accountant. Nothing is live today only because no `STRIPE_PRICE_*` exists and the super-admin
  billing switch defaults off — that is circumstance, not a guard, so treat the store as unreleased
  until this is answered.

---

## Phase GTM-1 — Finish what shipped half-done [MVP] ✅ Done (2026-11-07)

Three known loose ends from CG-4 and CG-5, each flagged in its own module doc.

**One thing changed shape.** GTM-1.1's "point the download path at S3" turned out to be two
different jobs: the _code_ (which was the real gap — the whole file was read into memory, and a
file on the `public` disk could be sold) and the _deployment_ (`composer require
league/flysystem-aws-s3-v3`, `ASSETS_DISK=s3`, `AWS_*`). The code is done and tested; the
dependency is **not installed** and the env is not set, because which bucket/provider holds paid
customer files is an ops decision, not a code one. `assets.md` and `creator-store.md` now say
exactly that instead of "S3 is still pending".

### Step GTM-1.1 — S3 for digital downloads [MVP] — **Closes the CG-4.3 gap** ✅ Done

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
- **Built 2026-11-07.** `AssetStorage::readStream()` + `LocalAssetStorage` (the route streamed
  through `contents()` before, i.e. a 500MB file into a PHP string — fine on local disk by luck,
  fatal on S3). `Asset::downloadRefusalReason()` is the single rule: a paid download must be on a
  **private** disk and must have been virus-scanned on the way in
  (`AssetSource::isVirusScanned()` — web/MCP uploads were, Canva/Drive/Dropbox/URL imports were
  not, and a new source case has to answer for itself). It is enforced in `SaveProductRequest`
  (the creator is told, with copy per reason) **and** in `DownloadController` (so a product that
  went live before the rule still never serves), where the buyer gets a plain 410 and the reason
  goes to the log. The signed, expiring link and the use-limit/refund checks were already there.
  Deployment is still outstanding: see the phase note above.

### Step GTM-1.2 — Stripe invoice from a brand deal [V1] — **Closes the CG-5.2 gap** ✅ Done

- An Action (`App\Actions\Deals\IssueDealInvoiceAction`) that turns a `Deal` into an invoice. Reuse
  the existing `Invoice` model and PDF/reminder pipeline from `billing.md` — a brand deal billing a
  sponsor is the same shape as an agency billing a client. Do **not** add a second invoice model.
- Wrap in `DB::transaction()` (invoice + line items + deal status + activity log). Log issue and
  failure with `deal_id` and `invoice_id`.
- **Done when**: tests cover issuing from a deal, the deal status moving once and only once, and a
  failed issue leaving no partial invoice; `brand-deals.md` drops the "no Stripe invoice" note.
- **Built 2026-11-07.** `IssueDealInvoiceAction` + `brand_deals.invoice_id` (not fillable) +
  `POST /deals/{deal}/invoice` + an "Invoice the sponsor" button on the deal row that becomes
  "Invoiced as INV-000012 (Sent)". Composed from `CreateInvoiceAction` and `SendInvoiceAction`, so
  the PDF, the email and the reminder pipeline are the agency ones and there is no second invoice
  model. Refused without an agreed fee or a sponsor address (without an address the pipeline would
  fall back to the creator's own billing contact — they would email themselves) and refused a
  second time, so neither case leaves a partial invoice.
- **Two deviations, both deliberate.** (1) **The deal's `DealStatus` is not moved.** The done-when
  asked for "the status moving once and only once", but `brand-deals.md` records that `DealStatus`
  is _not_ a state machine — the owner sets whichever status is true — and billing isn't delivery.
  `invoice_id` is the thing that moves once, and that is what the test asserts. (2) **It is not a
  Stripe-hosted invoice** through the connected account, as the step title suggests; it is our own
  invoice, as the step body asks for. Nothing charges a card.
- **Known limit**: Step B-1.4 makes the `/invoices` pages agency-only, and GTM-1.2 did not open
  them to business workspaces. A creator can raise, send and see a deal invoice, but cannot open,
  edit, remind on or mark it paid — that needs a business-shell invoice surface.

### Step GTM-1.3 — Pre-launch loose-ends sweep [MVP] ✅ Done

- Grep the module docs for "pending", "not built", "TODO", "known gap" and triage every hit into:
  fix now, record as a known limitation on the pricing/FAQ page, or delete the feature.
- Specifically confirm or close: CG-3.2's AI bio draft (Stretch, unbuilt — decide whether the editor
  still advertises it), and whether the comment inbox is on the Free plan (CG open question §5.6 —
  Buffer includes it on every plan).
- **Done when**: no module doc claims a capability the code doesn't have, and the decisions are
  recorded in the relevant docs.
- **Done 2026-11-07.** The sweep found the docs honest: every "not built"/"still pending" hit
  outside a Known Gaps section is a dated changelog entry (history, not a current claim). The two
  live ones were closed by GTM-1.1 and GTM-1.2. Decisions recorded:
    - **CG-3.2's AI "Draft my page" stays unbuilt for launch.** Nothing in the bio editor offers it,
      so no copy promises a missing button (the "Draft with AI" in `business/onboarding.tsx` is the
      brand-voice draft, which is built). Recorded in `bio-pages.md`.
    - **The comment inbox is on Free** — answers CG open question §5.6. `business_free` now grants
      `Feature::Inbox`, which is what the never-hard-gated `/inbox` surface already did, so the
      config stops claiming otherwise. Buffer includes it everywhere, it is the daily-return surface,
      its cost is bounded by Free's 3-account cap, and gating it now would take a shipped surface off
      existing free workspaces. One config line to reverse. Recorded in `plans.md` and marked
      answered in `business-competitor-gap.md`; the invariant test now names this one exception.
    - **`/pricing` states the store's tax position** — sales are paid into the creator's own Stripe
      account, so they are the seller of record and their own VAT/sales tax is theirs. Said before
      the first sale rather than discovered after it. (This does **not** answer GTM-0.3, which is
      still the launch blocker.)
    - **`business_free` cannot sell at all today** (`Feature::Store` is off), so GTM-0.3's question 5
      is a decision to make, not a hole to plug. Noted in `creator-store.md`.

---

## Phase GTM-2 — Audience and email [V1] — **Closes CG-6.1–6.3** ✅ Done (2026-11-07)

The largest creator-side gap, fully specified already, no platform dependency. A store with no email
list is half a business, and it is what Stan charges $29 for. The `Feature::EmailAutomation` gate
already exists in `App\Enums\Feature` — this phase gives it something to gate.

**One rule had to be split to work.** The done-whens asked for "unsubscribed and unconfirmed
contacts skipped" on every trigger. Taken literally that kills two of the three: nobody abandoning a
checkout, and nobody who has just bought, has confirmed a marketing opt-in, so `abandoned` and
`purchase` could never fire for anyone. They are now **transactional** — about the person's own
order or own unfinished checkout — and go out without a marketing opt-in; `welcome` and every
broadcast still require confirmed consent, and an unsubscribe stops all of them including the
transactional pair. This is still stricter than UK PECR, which would allow marketing similar
products to a buyer on the soft opt-in. `AudienceTrigger::requiresConsent()` is where it lives.

### Step GTM-2.1 — Contacts and capture [V1] ✅ Done

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
- **Built 2026-11-07.** `audience_contacts` (unique `(client_id, email)`) with
  `CaptureAudienceContactAction` as the **only** way a contact is created — never a duplicate,
  never a consent downgrade, and never a resurrection of an unsubscribed contact, which is the rule
  that matters when an import or a repeat purchase arrives. The `email_capture` bio block went live
  through `BioBlockType::live()` with no migration, as the CG-3.1 block model promised, and posts
  to `POST /@{handle}/subscribe` (honeypot + its own throttle, and an identical response whatever
  happened, so it can't be used to probe who is on a list). Confirm and unsubscribe are **signed
  routes** rather than a token column; unsubscribe never expires, because an email from six months
  ago still has to stop the next one. The buyer is captured at **checkout**, not fulfilment — an
  abandoned checkout needs a contact too — with `marketing_opt_in` as the only thing that sets
  consent. The import takes pasted CSV (`shortcut: not a file upload, upgrade when somebody asks`).
- **The export/deletion half could not be done as written:** B-13.2 doesn't exist, so there is no
  export or deletion path to add contacts to. The tables cascade from `clients`, the test proves
  it, and `audience.md` records that B-13.2 must list all three when it lands.

### Step GTM-2.2 — Automated emails [V1] ✅ Done

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
- **Built 2026-11-07.** One `audience_sends` row per contact + trigger + reference, claimed with
  `insertOrIgnore` **before** the mailable reaches the queue: a duplicate email can't be taken back,
  a missing one can be re-sent. `SendAudienceTriggerJob` exists for the _delay_, not the queue —
  the purchase follow-up goes a day later and the consent and unsubscribe checks have to run at
  delivery, not at dispatch.
- **Two simplifications.** The abandoned trigger fires from the existing hourly
  `ExpireStaleOrdersAction` cancellation rather than Stripe's `checkout.session.expired` — same
  event, a webhook we already don't need, and it makes "cancel the email if the order completes"
  free: a completed order never reaches that sweep, so there is nothing to cancel. The plan gate
  uses `allows()` here, not `ensure()`: this runs in a job, where an exception retries forever and
  an upgrade prompt has nobody to show itself to.

### Step GTM-2.3 — Broadcasts with AI drafts [V1] ✅ Done

- A one-off email to a segment (all confirmed, buyers of product X). "Draft with AI" fills it from
  the Brand Brain through the existing `WritingAssistant`/`AiProvider` seam, metered against
  `Feature::AiTextCredits`.
- **The owner sends. AI never sends.** The send endpoint requires an authenticated human request;
  no job may originate a broadcast.
- **Done when**: tests cover segment selection, metering, a human-only send path, and that an
  unsubscribed contact is excluded from every segment.
- **Built 2026-11-07.** `audience_broadcasts` plus `SendAudienceBroadcastAction`, which takes the
  sending `User` and is only reachable from `POST /audience/broadcasts`. No job, command or
  schedule can originate a broadcast, which is the real guard behind "AI never sends"; the AI
  endpoint returns JSON text and nothing else. An unconfirmed or unsubscribed contact is in no
  segment, so there is no way to compose one that reaches them.
- **"Draft with AI" reuses the caption rewrite seam** (`RewriteCaptionAction` with
  `WritingTool::Expand` on the owner's notes), so it inherits the Brand Brain grounding, the
  banned-phrase guardrail and the `AiTextCredits` metering without a new provider method
  (`shortcut: it writes the body, not the subject line; upgrade to its own AiProvider method when
subject lines or longer-form structure are wanted`).

---

## Phase GTM-3 — White label and custom domains [V1] ✅ Done (2026-11-07)

Opens the reseller-agency segment (a hard disqualifier today) and the paid-domain upsell that Stan,
Beacons and Linktree all charge for. Two new `Feature` gates: `WhiteLabel`, `CustomDomain`.

### Step GTM-3.1 — Agency white label [V1] ✅ Done

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
- **Built 2026-11-07.** Six nullable columns on `organizations`, resolved once by
  `BrandingResolver` → `Branding` and read by all three surfaces, so none of them can disagree
  about who the client is dealing with. The portal shell takes the accent, the logo and the footer
  (and drops the Alima credit); the report PDF takes the band mark, the accent word and the footer;
  the seven client-facing mailables share one header and footer partial and set the agency's
  display name and reply-to. The logo goes through `AssetStorage` on its own endpoint so the
  settings `PUT` stays JSON.
- **The branding is stored on any plan and resolved only with the gate.** A downgrade therefore
  takes it off every client surface at once with no migration, and an upgrade doesn't ask the
  agency to retype it.
- **Deviation: white label is on every agency tier, not "from `agency_standard` up".** GTM-0.1 had
  already decided and published the opposite — every agency tier grants every gate, tiers differ
  on capacity, and `/pricing` says "Tiers differ on how many brands and seats, never on features".
  The shipped decision won over this line. The consequence is a pricing one and it is real: white
  label sells at £49/mo against Cloud Campaign's $199/$299. Tiering it is one line in
  `config/plans.php` plus one test assertion; recorded in `plans.md`.
- **Not done: a per-agency sending domain.** The display name and reply-to are the agency's, but
  the envelope from-address stays our verified domain, because SPF and DKIM are signed for it.
  And the logo is in the portal and the PDF but **not** in email — most clients block remote
  images and a signed URL lapses, so it would be unreliable there. Both in `settings.md`.

### Step GTM-3.2 — Custom domains for bio pages [V1] ✅ Done

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
- **Built 2026-11-07.** `bio_page_domains` (hostname globally unique and normalised, max 3 per
  page), the `DnsResolver` contract so verification is tested without the network, and
  `VerifyBioPageDomainAction` as **both** the "Check now" button and the nightly
  `bio-domains:reverify` sweep — which is the point: a repointed hostname loses `verified_at`
  and stops serving somebody else's page the same night. Two proofs are accepted: the CNAME that
  actually routes the traffic, or a TXT record carrying the row's token, so an apex domain (where a
  CNAME is illegal) can be proved too.
- **Hostname routing turned out to be one line, not a routing layer.** Routes here are not
  host-scoped, so every route already matches on a custom host, and Laravel generates URLs against
  the current request — so the page's own links stay on the creator's domain by themselves. The
  only split needed was the root: `/` is the marketing page on our host and that creator's bio page
  on a verified one. No middleware, no duplicated route group.
- ****§5.5 answered**: the `@handle` address stays canonical and never stops working; a custom
  domain sits alongside it. A dedicated `alima.bio` handle domain wasn't built and isn't needed.
- **The infrastructure half is the operator's, and it is not done.** Alima has no ACME client:
  `ssl_status` records what the TLS layer reports and nothing writes anything but `pending` today.
  `ops.md` lists the four operator steps (set `BIO_DOMAIN_TARGET`, accept unknown `Host` headers,
  terminate TLS with on-demand/wildcard certs, point any ask-endpoint at a verified-hostname
  check). **Until those are done a creator can verify a domain and still get a certificate error.**

---

## Phase GTM-4 — Competitor benchmarking [V1] ✅ Done (2026-11-07)

**Open question §7 answered first, as the plan asked: one platform, not four.** Instagram, through
Meta's **Business Discovery** — asked through an IG Business account the brand itself owns,
returning only figures Instagram already shows publicly, with no agreement needed from the profile
being read. That is the endpoint Meta provides for precisely this. No other network we publish to
offers a comparable read of an account we don't own, and the alternative is logged-out scraping,
which is against all of their terms and would put the product at risk for a nice-to-have. So an
unsupported platform is **refused at the point of adding a handle**, rather than accepted and left
to produce nothing but gaps. Recorded in `benchmarks.md`.

The credible version of listening. Metricool sells 100 tracked competitor profiles at €16/month, and
Gartner's new Magic Quadrant is named "Social Media Management **and** Listening" — having nothing
here loses RFPs on the category definition alone. We already have the seam: `ProfileFeedSource`
reads a public Instagram profile for the grid planner, and `social_account_metrics` is the storage
pattern.

### Step GTM-4.1 — Tracked profiles and nightly readings [V1] ✅ Done

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
- **Built 2026-11-07.** `tracked_profiles` + `tracked_profile_metrics`, shaped exactly like
  `social_account_metrics` (one row per profile per day, unique, upserted) so our figures and
  theirs are compared like with like and a re-run can never double-count. The
  `CompetitorProfileSource` contract sits alongside `ProfileFeedSource` with the same never-throw
  rule, `MetaBusinessDiscoveryCompetitorSource` reuses the grid preview's Graph plumbing verbatim,
  and `CompositeCompetitorProfileSource` catches anything an implementation throws anyway.
  `benchmarks:sync` (nightly 04:00) queues one unique job per org **that has tracked profiles**, so
  it costs nothing for everybody who doesn't use it.
- **`Feature::TrackedProfiles` is a limit, counted per organization**, not per brand: fifty brands
  each tracking one profile is fifty nightly reads against one Graph budget. Free 0, Pro and
  Creator Pro 3, agency Starter 25 / Standard 50 / Scale 100. For context, Metricool sells 100 at
  €16/month.
- **Adding a profile reads it immediately.** A handle we will never get a figure for says so there
  and then instead of looking fine until the next morning.
- **One thing this step taught the plan config:** a new limit case defaults to `null`, which means
  _unlimited_, so forgetting one plan gives the feature away. Noted in `plans.md`.

### Step GTM-4.2 — Benchmark view and report block [V1] ✅ Done

- A benchmarks panel on the client's analytics page: our account against its tracked profiles over
  the selected window, with the honest gaps shown as gaps.
- A benchmark block in the M-3.3 client report, and a plain-English takeaway through the existing
  `InsightsSummarizer` seam ("you posted less than three of four tracked accounts this month").
- Gate behind `Feature::CompetitorBenchmarks`.
- **Done when**: tests cover the panel with and without data, the report block, the gate, and that a
  window with no readings renders empty rather than zero.
- **Built 2026-11-07.** `GetClientBenchmarksAction` feeds both the "How you compare" panel on the
  client analytics page and the block frozen into the client-report snapshot (PDF **and** the
  portal page), with the takeaway coming through the existing `InsightsSummarizer` seam and
  failing quietly to no takeaway.
- **The gap rule is the whole design.** Every figure is nullable from the Graph response through
  the column, the payload and the component; a source that can't read a profile writes **no row**,
  and the panel shows an em dash with the reason beside it. Nothing substitutes 0 for "we don't
  know", because that would make a competitor look worse than they are, which is the one thing a
  benchmark must not do. A reading that falls outside the window is a gap too, not a stale figure.
- **It is a page prop on `analytics/client`, not part of the shared summary.**
  `GetOrganizationAnalyticsSummaryAction` feeds four surfaces and benchmarks belong on exactly one,
  so the shared action was left alone.
- **A downgrade stops the reads, not just the panel** — the sweep skips an org that has lost the
  gate, so an expired plan stops costing API calls.
- Three measures only (followers, posts in the window, interactions per post), because they are
  what both sides can be compared on. Nothing stores a competitor's content.

### Step GTM-4.3 — Keyword mentions [Stretch] — **Dropped (2026-11-07)**

- Named keywords per client, matched against the sources we already ingest (comments via
  `CommentSource`, trends via `TrendSource`). **No new crawling.** If that yields too little to be
  useful, delete this step rather than buying a listening vendor.
- **Done when**: shipped with tests, or recorded as dropped with the reason in `analytics.md`.
- **Dropped, and recorded in `analytics.md`.** The step's own constraint was "no new crawling" —
  match keywords against the sources we already ingest. Those sources turn out to be the wrong
  ones: `CommentSource` reads comments on **our own** posts, so a match tells a brand somebody used
  a word under a post they already own, and `TrendSource` returns platform-level trends with no
  per-brand mention volume at all. Neither answers "who is talking about us", which is the only
  question this would be bought for. Shipping it would have produced a panel that looks like
  listening and isn't — worse than an honest absence, and §1.1 already rules out buying a real
  listening vendor. Revisit only if a platform ships a mentions endpoint we may read.

---

## Phase GTM-5 — Paid ads reporting, read-only [V1] ✅ Done in code (2026-11-07), off until `ads_read`

Agencies report on ad spend monthly. Metricool manages ads, Later Scale shows Meta Ads analytics,
Cloud Campaign sells paid social reporting on its Agency tier. Reporting is ~10% of the work of
management and most of the value.

### Step GTM-5.1 — `AdsSource` contract and metrics [V1] ✅ Done

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
- **Built 2026-11-07.** `ad_accounts` / `ad_account_metrics` shaped exactly like `social_accounts` /
  `social_account_metrics`, `MetaAdsSource` over the Marketing API's insights endpoint, `NullAdsSource`
  as the **default**, and `ads:sync` nightly at 04:30. The whole window is re-read each night, not
  just yesterday, because ad platforms restate recent days as conversions land; the daily upsert is
  what makes that safe.
- **"No code path can write" is asserted by reflection.** `AdsSource` has exactly `key()` and
  `accountMetrics()`, and the test checks that method list, so a write method fails the suite before
  it reaches a reviewer. The PHPDoc says why it is a rule rather than a preference.
- **CPC and CPA aren't columns** — spend ÷ clicks and spend ÷ conversions, derived where shown, so
  a stored ratio can't disagree with its own inputs. Both null when the denominator is 0: a cost per
  click with no clicks is unanswerable, not infinite.
- **It is off until Meta grants `ads_read`** (Phase 5 work, not done). `ADS_PROVIDER` is unset by
  default, so every read returns null and the panels show honest gaps rather than wrong numbers.
  **This is what stands between the code and a usable feature.**

### Step GTM-5.2 — Ads in the report and the dashboard [V1] ✅ Done

- An ads section in the M-3.3 client report and on the client dashboard: spend, results, trend, and
  organic-vs-paid side by side.
- Gate behind `Feature::AdsReporting` (agency top tier per GTM-0.1).
- **Done when**: tests cover the section with data, with a disconnected account, and the gate;
  `analytics.md` documents the new tables and their source.
- **Built 2026-11-07.** `GetClientAdsAction` feeds the "Ad spend" panel on the client analytics page
  and the "Paid and organic" block frozen into the report snapshot (PDF and portal), with the
  organic figures beside the paid ones. A disconnected account shows em dashes and the reason: in a
  spend report a 0 would read as "we spent nothing", which is a different and false claim. The new
  tables are documented in **`ads.md`**, with a pointer from `analytics.md`.
- **Gate deviation, same as GTM-3.1.** The step says "agency top tier"; GTM-0.1 had already decided
  and published that every agency tier grants every gate. `Feature::AdsReporting` is therefore on
  all three agency tiers and no business or creator plan. One config line to tier it.
- **Known limit**: the panel sums minor units across accounts and labels the total with the first
  account's currency, which is wrong for an agency running one account in GBP and another in USD. It
  should group by currency as the funnel figures do. Recorded in `ads.md`.

---

## Phase GTM-6 — Mobile reach [V1] — **Closes CG-8.1** ⚠️ Code and copy done; **listing and device test are yours**

Planoly's free tier is **mobile-app-only** (10 uploads/month). An app-store listing is how creators
enter this category, and we have a PWA and working push already (CG-1.3).

### Step GTM-6.1 — Trusted Web Activity to Google Play [V1] ⚠️ Half mine, half yours

- Wrap the existing PWA as a TWA: `assetlinks.json` on the app domain, a minimal Android project,
  Play listing (icons, screenshots, privacy policy, data-safety declaration).
- Verify push notifications arrive in the installed TWA — that is the whole point of shipping it.
- Record the decision and the CG-1.3 delivery evidence in `business-workspaces.md`, which closes
  CG-8.1's "decide between PWA, TWA or native".
- **Done when**: the app is listed, push is confirmed on a real device, and the decision log names
  TWA as the answer and native as explicitly declined.
- **Done 2026-11-07, as far as it can be from here.** `/.well-known/assetlinks.json` served from
  `config/twa.php`; `android/twa-manifest.json` as the Bubblewrap input; `android/README.md` with
  the exact build and verification steps; `android/play-listing.md` with the store copy, the graphics
  list, the **data-safety declaration matched to what the code actually does**, and a release
  checklist. The decision is recorded in `business-workspaces.md`, which closes CG-8.1.
- **No Android source is in the repository, on purpose.** A TWA's project is entirely generated from
  that one config file; a hand-written copy would be a second thing to keep in step with the web
  manifest for no benefit. The keystore is gitignored — losing control of it means losing the
  ability to publish updates.
- **`assetlinks.json` 404s until `ANDROID_PACKAGE` and `ANDROID_FINGERPRINTS` are set**, rather than
  serving a file that would fail Android's verification quietly. The symptom of getting that wrong
  is an address bar across the top of the installed app, which reads like a Play problem and is a
  deploy problem.
- **Two things I cannot do and have not pretended to:** list the app on Play, and confirm a push
  notification arrives on a real device. The second is the whole point of shipping it, so **this
  step is not finished until somebody does it.** The checklist in `android/play-listing.md` is what
  to work through.

---

## Phase GTM-7 — Launch mechanics [MVP/V1] ✅ Done (2026-11-07)

### Step GTM-7.1 — Funnel metrics [V1] — **Closes CG-9.1 / B-12.4** ✅ Done

- Trial start → paid conversion, time to first published post, % of workspaces with a published bio
  page, store GMV (from `orders`, never from the Stripe balance), churn by plan, and — new — agency
  seat and brand utilisation against the GTM-0.1 limits, so the next price change has evidence.
- **Done when**: B-12.4's done-when plus a fixture per new metric, visible in the super-admin console.
- **Built 2026-11-07.** `GetFunnelMetricsAction` + a funnel panel on `/super-admin/analytics`:
  trial → paid, median time to first published post, bio-page adoption, store GMV per currency,
  churn by plan, and agency cap utilisation against the GTM-0.1 limits.
- **Two rules it keeps.** Store GMV comes from our own `orders`, never a Stripe balance — creators
  are paid directly into their own connected accounts, so our balance has never seen that money and
  asking it would give a wrong answer with total confidence. And **a denominator of zero is `null`,
  not 0%**: a new install has no conversion rate, and printing 0% would read as "nobody converts".
- **Lifetime to date, not range-filtered**, so it is a separate page prop rather than another
  section of the platform summary. "How long does a new workspace take to publish" is not a question
  about last month.
- **The agency figure is the one with teeth**: an agency at 90% of its brands is an upgrade waiting
  to happen, and a whole tier averaging a fifth of its cap is a cap set too high. That is the
  evidence the next price change needs, and the reason the open AI-credit question in `plans.md`
  can be settled with data rather than a guess.

### Step GTM-7.2 — Quarterly competitor review [V1] — **Closes CG-9.2** ✅ Done

- An artisan command plus scheduled super-admin notification each quarter to re-check competitor
  pricing and refresh the gap docs. Seed it with the November 2026 findings at the top of this file
  and set the first review date.
- **Done when**: the command and its test exist and the first review date is in
  `business-workspaces.md`.
- **Built 2026-11-07.** `competitors:review-due` runs daily at 09:00, stays silent until
  `COMPETITORS_LAST_CHECKED` is more than a quarter old, then raises one throttled `OpsAlerter`
  alert naming every figure currently quoted. Seeded with the November 2026 findings in
  `config/competitors.php`; **the first review date is 2027-02-05**, recorded in
  `business-workspaces.md`.
- **It checks nothing automatically, deliberately.** Vendors move prices behind marketing pages,
  paywalls and A/B tests; a scraper would give a confident wrong answer, and a wrong price on a
  public comparison page is worse than having no page. So it nags a human and stops nagging when the
  date moves.

### Step GTM-7.3 — Distribution: comparison pages and affiliates [MVP] ✅ Done

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
- **Built 2026-11-07.** `/compare/{rival}` for Planoly, Metricool, Cloud Campaign and Later,
  generated from `config/competitors.php` so every figure carries one dated "last checked" that
  GTM-7.2 nags about. **Each page leads with what the rival does better** — a comparison page that
  pretends otherwise gets quoted back at you, and the honest version is the one that converts. We
  never claim a competitor lacks something, only what they charge and what they say they include.
- **The referral programme**: `CaptureReferralCode` middleware stores `?ref=CODE` from any page for
  30 days, **validating it against a live partner before storing**, so the cookie can only hold a
  real code. **First touch wins** — whoever introduced somebody earned it, not whichever link they
  clicked last. Nothing about the visitor is stored: one cookie, one code, no database row until
  they sign up.
- **Attribution lives on `organizations`**, as the step asked (reuse the Cashier customer record):
  the org is already the billable, so it is the thing a commission is owed on, and a join table
  would be a second place for the same one-to-one fact to disagree with itself.
- **A payout is a record of money already sent, not an instruction to send any.** There is no
  payment integration, on purpose: paying a partner is a bank transfer and a conversation, and
  automating it before the first partner exists would be building the wrong thing twice. What is
  _owed_ is derived when somebody settles up, so there is only ever one number that can be wrong.
- Comparison and partner links are in the marketing footer, because an unlinked page earns nothing;
  a test asserts the footer slugs against config so the two can't drift.
- **Not done: the graphics.** The comparison pages are text. No screenshots, no feature-matrix
  table, and no SEO work beyond honest copy at a crawlable URL.

### Step GTM-7.4 — Reposition MCP on depth [V1] ✅ Done

- Rewrite the MCP marketing claim now that Buffer, Metricool, Planable and Hootsuite all have one:
  lead on org-scoped isolation, capability scopes, per-connection rate limits, the audit trail, the
  two master switches and the checked-in security review — the things a buyer's security review asks
  about and the others' single-user connectors don't answer.
- **Done when**: the claim is live on the marketing site and `mcp.md` records that MCP is now
  table stakes, with depth as the differentiator.
- **Built 2026-11-07.** There turned out to be **no MCP claim to rewrite** — the marketing site
  never mentioned it. So `/agents` makes one, and makes it about depth: org-scoped isolation,
  capability scopes, per-connection rate limits, the audit trail, the two master switches and the
  checked-in security review — the things a buyer's security review asks about and a single-user
  connector can't answer. Plus the product rule: an assistant drafts, a person sends.
- `mcp.md` records that MCP is table stakes (Buffer and Metricool on free plans, Planable on every
  tier, Hootsuite product-level), and that the next review should check whether competitors have
  closed the **depth** gap rather than whether they have a server at all.

---

## Phase GTM-8 — Explicitly deferred [Stretch] — nothing to build, by design

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

**Launch blockers** (nothing ships publicly without these): GTM-0.1 ✅, GTM-0.2 ✅, **GTM-0.3 —
still open and still the only one**, GTM-1.1 ✅ (code; the S3 deployment step is outstanding),
GTM-1.3 ✅.

**First wave after launch**, in this order — ~~GTM-2~~, ~~GTM-3.1~~ and ~~GTM-4~~ ✅ **all done
2026-11-07**, then GTM-7.1/7.2.

**Second wave**: ~~GTM-3.2~~, ~~GTM-5~~ and ~~GTM-6~~ ✅ **all done 2026-11-07** (GTM-5 is off until
Meta grants `ads_read`; GTM-6 still needs the Play listing and an on-device push check).

~~**Start now regardless**: GTM-7.3.~~ ✅ **Done 2026-11-07** — four comparison pages and the
affiliate programme are live, which is the thing in this plan with the longest lead time, so the
clock is running.

## 2.1 Where the plan stands (2026-11-07)

Every phase is built except the parts that are not code:

| Phase                             | State                                                                                                     |
| --------------------------------- | --------------------------------------------------------------------------------------------------------- |
| GTM-0                             | Done except **GTM-0.3**, which is the only launch blocker left                                            |
| GTM-1, GTM-2, GTM-3, GTM-4, GTM-7 | Done                                                                                                      |
| GTM-5                             | Code done; **off until `ADS_PROVIDER=meta` and `ads_read` on our Meta app**                               |
| GTM-6                             | Wrapper, listing copy and data-safety done; **Play listing and the on-device push check are outstanding** |
| GTM-8                             | Nothing to build, by design                                                                               |

**The three things only a person can close**, in order of what blocks what:

1. **GTM-0.3** — tax, VAT and which entity holds the Stripe platform account. Needs an accountant.
   Nothing takes money until this is answered in writing.
2. **GTM-6.1** — upload to Play, set `ANDROID_PACKAGE` / `ANDROID_FINGERPRINTS`, and confirm a push
   notification arrives on a real device. The last one is the whole point of shipping it.
3. **GTM-5** — `ads_read` on the Meta app (Phase 5 work), then `ADS_PROVIDER=meta`.

Plus the deployment steps already recorded elsewhere: `league/flysystem-aws-s3-v3` and
`ASSETS_DISK=s3` for paid downloads (GTM-1.1), TLS for bio-page custom domains (GTM-3.2,
`ops.md`), the `STRIPE_PRICE_AGENCY_*` price ids (GTM-0.1), and `MAIL_MAILER=resend` before any
audience email goes out (GTM-2).

Rough sizing: GTM-0 is decisions plus a config change (small). GTM-1 is small. GTM-2 is medium and
fully specified. GTM-3.1 is medium; GTM-3.2 carries real infrastructure risk (DNS/TLS) — give it a
dedicated review. GTM-4 is medium and mostly composition over `ProfileFeedSource`. GTM-5 is medium.
GTM-6 is small but has an unpredictable Play review queue, so start it early.

Give GTM-1.1 (signed URLs), GTM-2.1 (consent) and GTM-3.2 (hostname routing) a pass with
`graphy-convention-reviewer` before merge, and run the full agency suite after GTM-0.1 and GTM-3.1 —
both change behaviour for every existing org.

---

## 4. Module docs touched

- `plans.md` — GTM-0.1, GTM-0.2, GTM-2 (`email_automation` got its surface), and every new
  `Feature` gate/limit in GTM-3, GTM-4, GTM-5.
- `creator-store.md` — GTM-0.3, GTM-1.1.
- `brand-deals.md` — GTM-1.2.
- `bio-pages.md` — GTM-2.1 (capture block), GTM-3.2 (domains).
- `analytics.md` — GTM-4.2 (pointer), GTM-4.3 (dropped, with the reason), GTM-5.2, GTM-7.1.
- `client-portal.md`, `settings.md`, `billing.md`, `analytics.md` — GTM-3.1.
- `ops.md` — GTM-3.2 (DNS/TLS), GTM-4.1 (nightly jobs).
- `business-workspaces.md` — GTM-6.1, GTM-7.2 decision logs.
- `marketing.md` — GTM-7.3.
- `mcp.md` — GTM-7.4.
- **New**: ~~`audience.md` (GTM-2)~~ ✅, ~~`benchmarks.md` (GTM-4)~~ ✅, `ads.md` (GTM-5). Each needs its row in
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
