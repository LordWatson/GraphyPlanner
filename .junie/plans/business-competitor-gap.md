# Graphy — Business & Creator Competitor Gap (Full Scope)

The business workspace (`.junie/plans/business-workspaces.md`, Phases B-0–B-8 done) gives a solo
business or creator everything they need to plan and publish on their own. The October 2026
competitor review compared it with Planoly, Later, Plann (Linktree), Buffer, Metricool, Publer,
Hootsuite, Stan, Beacons, Linktree and the free Meta tools. It found:

- **Where we win:** AI that knows the brand (brand voice and never-say rules, local and seasonal
  ideas, caption from a photo, plan my week, repurpose, brand-fit, Image Studio), insights that
  explain themselves (takeaways + weekly digest + best times), nine networks with one-tap connect
  (including Google Business, Threads, Pinterest and X), a media kit built from real data with a
  sponsor share-preview, human-in-the-loop AI, and a path from solo to agency (B-12.1).
- **Where we're losing:** no price and no checkout (B-9.3 deferred), two features built but not
  switched on for solo users (grid planner, comment inbox), no link in bio and nothing to sell, no
  native app (push built but not delivered), no email or DM automation, and no brand recognition
  yet. Free tools (Meta Business Suite, the Instagram app) set the floor at £0.
- **Pricing in the segment:** solo plans cluster at $14–$30/month. Nearly every tool has a free plan
  or a 14-day trial. Selling with a 0% platform fee is standard on paid creator plans (Planoly $14+,
  Stan $29, Beacons $30, Linktree $35).

Full analysis: the "Solo and creator competitor gap analysis" and "Creator commerce gap analysis"
docs in the Alima Planner project.

This plan closes those gaps in order of impact for effort. It sits alongside
`.junie/plans/business-workspaces.md` and **reuses its steps where they exist**. A step here that
says "Delivers B-x.y" builds that step (with the changes listed) and marks **both** steps done. It
never builds the same thing twice. How to use it (same rules as the other plans):

- Each step is sized to hand to Junie as one unit of work: a goal, the files/models it touches, and
  a **Done when**.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), Actions/Services, interfaces in `App\Contracts\*` where swappable, `DB::transaction()`
  for multi-write logic, logging at boundaries with ids (never secrets, never raw prompts), PHPUnit
  coverage (happy path + rejections + regression tests), PHPStan level 7 with no new ignores, and the
  matching `.junie/modules/*.md` updated in the same change.
- Frontend steps follow `.junie/brand-guidelines.md` and use the `graphy-frontend-page` skill
  (house components, semantic tokens, StatusPill for status, Alima voice in copy).
- Step ids are prefixed `CG-` (e.g. `Step CG-3.1`) so they never collide with `B-`, `I-` or plain
  step numbers. Mark finished steps `✅ Done` with an "Implemented via …" note.
- Priority tags: **[MVP]** needed before we charge solo users · **[V1]** needed to compete head-on
  with Planoly/Later for this segment · **[Stretch]**.

---

## 1. Principles

1. **Switch on before building.** Anything the agency side already has (grid planner, inbox) goes
   into the business shell through `CurrentWorkspace` and the existing Actions. Nothing is forked.
2. **Charge before expanding.** Plans and payments (Phase CG-2) land before the creator store, so
   every new feature ships behind an `Entitlements` limit from day one.
3. **0% fee on sales on paid plans.** It's the segment's norm. Alima makes its money from the
   subscription, not from a cut of what creators sell.
4. **Two Stripe integrations, kept apart.** Our subscriptions use Cashier (B-9.3). Creators' sales
   go through Stripe Connect behind `App\Contracts\PaymentProvider`. They share no tables, webhooks
   or config keys.
5. **AI suggests, people decide.** Launch campaigns, email drafts and bio-page drafts are drafts. A
   human schedules, sends and publishes, as everywhere else.
6. **Claims we can prove.** Any competitor comparison on a marketing page is checked against the
   competitor's live pricing page on the day it ships, and dated.

### 1.1 Non-goals (for this plan)

- A marketplace or discovery feed (Gumroad Discover style). Creators bring their own traffic.
- Becoming merchant of record ourselves (unless CG-0.2 decides on a partner; see §5).
- Paid ads management (still out of scope, as in spec §13).
- Agency-side changes, except where a shared Action needs a business variant.

---

## 2. Prerequisites

- **B-9.1–B-9.2** (`Entitlements`, AI allowance metering) are planned in `business-workspaces.md`
  and delivered by CG-2.2–CG-2.3 here.
- **B-13.1** abuse and cost safeguards must land before CG-4 (public checkout is a new fraud
  surface: card testing, refund abuse, stolen-content sales).
- **B-13.2** data export and account deletion must cover the new tables (orders, buyers, bio pages)
  before CG-4 ships to real money.
- **S3 asset storage** (pending in `assets.md`) before CG-4.3. Paid downloads must not be served
  from local disk.
- **I-10.4** link tracking (referenced by B-11.3) before CG-3.3, or CG-3.3 builds the minimum
  click-tracking it needs.

---

## Phase CG-0 — Decisions & groundwork

### Step CG-0.1 — Record positioning and pricing [MVP]
- Write the decisions into `.junie/modules/business-workspaces.md` (Resolved open questions):
  positioning ("the AI marketing partner for one brand"), plan names and prices, currencies, and
  what each plan includes.
- Starting proposal from the review (to confirm or change):

  | Plan | Price | Includes |
  |---|---|---|
  | `business_free` | £0 | ~3 accounts, 30 scheduled posts/month, small AI allowance |
  | `business_pro` | £15/month | 1 brand, all networks, unlimited scheduling, full AI allowance, insights + digest, grid planner, inbox |
  | `creator_pro` | £29/month | Pro + public media kit, deals tracker, link in bio, store at 0% fee, 2 seats |

  Annual = two months free. USD equivalents for the US market are set in the same config.
- **Done when**: the module doc records the final plan table and the open question §5.2 of
  `business-workspaces.md` (free tier) is marked resolved with these limits.

### Step CG-0.2 — Payments, tax and entity decisions [MVP]
- Decide and record:
  1. Which legal entity (UK or UAE) holds the Stripe platform account for Cashier and for Connect.
  2. Connect account type (Express recommended: Stripe-hosted onboarding and dashboard, least
     compliance surface for us).
  3. Sales tax/VAT on creators' sales: the creator's responsibility with Stripe Tax offered on their
     connected account (Planoly/Stan model), or a merchant-of-record partner (Fourthwall/Gumroad
     model). Get an accountant's view on UK/EU VAT for digital goods first.
  4. Launch currencies for creator sales (GBP + USD minimum; AED if Dubai matters early).
  5. Whether the free plan can sell, and at what fee (Beacons 9%, Linktree 12%, Planoly no free web
     plan).
- **Done when**: the answers are in the module doc and CG-4's steps are updated to match.

---

## Phase CG-1 — Switch on what's built [MVP]

Goal: a solo user gets the grid planner, the comment inbox and real push reminders. These are
the features Planoly and Buffer lead with, and Alima already has all three.

### Step CG-1.1 — Comment inbox in the business shell [MVP]
- Today every inbox route sits under `['portal.redirect','workspace:agency']`. Add business routes
  (`business.` prefix, brand-implicit): `GET /inbox`, `GET /inbox/{conversation}`, `PATCH`,
  `POST …/reply`, `POST …/ai/draft`, `POST /inbox/refresh`, saved replies. Thin
  `Business\InboxController` resolves the brand from `CurrentWorkspace` and calls the **same**
  inbox Actions, filtered to the primary brand.
- Hide assignment UI when the workspace has one seat. Map roles via `Role::labelFor()`.
- Add an "Inbox" chapter to `businessChapters` (`resources/js/lib/chapters.ts`) and the ⌘K palette.
  Add an open-and-needs-reply count to the Today dashboard's **needsYou** (extend
  `GetBusinessDashboardAction`).
- Business copy pass (`WorkspaceCopy` / `workspace-copy.ts`): no "client" wording.
- **Done when**: feature tests cover a business owner reading, AI-drafting and sending a reply on
  their brand only; an agency conversation id is 404 from the business routes; agency inbox tests
  stay green; `inbox.md` and `business-workspaces.md` are updated.

### Step CG-1.2 — Grid planner in the business shell [MVP]
- Brand-implicit business routes: `GET /grid` (the brand's grid plans), `GET /grid/{grid}/edit` and
  the existing tile/workflow/production/AI endpoints, resolving `{client}` from `CurrentWorkspace`
  (reuse `ResolvesWorkspaceBrand` in the shared Form Requests, as B-4 did).
- Business approval model: the grid's client-review path becomes **internal only** by default (like
  `default_approval_mode = internal_only` for posts). The review link stays available as the
  "Share preview" for a sponsor or partner (B-3.4 pattern). No portal grid routes for business orgs.
- Sidebar: "Grid" as a section of Posts (like Collections), visible when the brand has a connected
  Instagram account. Otherwise an `EmptyState` explains why.
- Close the known gap that the grids planner is an unguarded shared surface: the agency
  `/grids` hub gets `workspace:agency`.
- **Done when**: tests cover a business owner creating a plan, turning tiles into posts and
  scheduling the sequence; another org's grid is 404; the agency hub is unreachable from a business
  org; `grid-planner.md` and `business-workspaces.md` are updated.

### Step CG-1.3 — Deliver push reminders [MVP]
- Bind a transport-backed `App\Contracts\PushNotifier` (Web Push with VAPID, e.g.
  `minishlink/web-push`) in place of the logging default when `push.enabled` and the VAPID key pair
  are set. Keys come from env only, never from props or logs.
- `Gone` results prune the subscription (already modelled by `PushDeliveryResult`). Failures are
  logged with the subscription id, never the endpoint or keys.
- Add the two notifications B-7.4 names as follow-ups: publish failure and "nothing scheduled
  tomorrow", through the same contract.
- **Done when**: tests with a fake transport cover delivered/failed/gone, pruning, and the two new
  notifications; a manual check on Android Chrome and iOS Safari (installed PWA) is recorded in the
  module doc.

### Step CG-1.4 — Today dashboard and copy for the new surfaces [V1]
- Today's **needsYou** includes unanswered comments (CG-1.1) and grid order-at-risk (CG-1.2).
- Onboarding checklist (`OnboardingProgress`) adds an optional "Plan your grid" item for Instagram
  creators.
- **Done when**: dashboard tests assert both new item types appear only for the primary brand.

---

## Phase CG-2 — Plans & payments [MVP]

Goal: a solo user can start a trial, pay by card, upgrade and downgrade, and every limit is
enforced in Actions. Delivers **B-9.1–B-9.4** and **B-12.2** with the competitor-informed pricing.

### Step CG-2.1 — Plan definitions [MVP]
- `config/plans.php` with `business_free`, `business_pro`, `creator_pro` (and `agency_*`
  unchanged = no limits). Each plan holds: GBP/USD monthly and annual Stripe price ids, limits for
  every `Feature`, and new boolean features this plan needs: `grid_planner`, `inbox`, `bio_page`,
  `store`, `public_media_kit`, `brand_deals`, `email_automation`.
- **Done when**: a test asserts every plan defines every `Feature` (no silent nulls) and that agency
  plans are unlimited.

### Step CG-2.2 — `Entitlements` contract [MVP]
- **Delivers B-9.1** as specified there, extended with the boolean features above
  (`allows(Organization, Feature)`), enforced in the Actions for CG-1.1, CG-1.2, CG-3 and CG-4
  surfaces as they land.
- **Done when**: B-9.1's done-when plus one test per new boolean feature (allowed plan passes, free
  plan gets `PlanLimitReachedException`).

### Step CG-2.3 — AI allowance metering [MVP]
- **Delivers B-9.2** unchanged. Allowances are set so `business_pro` beats Later Starter's 5
  credits/month by a wide margin while staying within DeepSeek/Gemini cost per seat.
- **Done when**: B-9.2's done-when.

### Step CG-2.4 — Subscriptions with Cashier [MVP]
- **Delivers B-9.3** (promoted from V1 to MVP: the review shows every competitor self-serves a
  trial and checkout). 14-day trial on paid plans. Stripe Checkout and Customer Portal, idempotent
  `SyncSubscriptionAction`, behind the `billing.enabled` Pennant flag.
- **Done when**: B-9.3's done-when.

### Step CG-2.5 — Upgrade prompts and the plan page [MVP]
- `/settings/plan`: current plan, usage meters (accounts, posts this month, AI credits), upgrade and
  manage-billing buttons. Limit hits render a friendly upgrade prompt (`PlanLimitReachedException` →
  Inertia flash + `EmptyState`-style panel naming the plan that unlocks it), never a 403.
- **Done when**: feature tests cover the prompt for each limit and that ClientReviewer-style roles
  never see billing.

### Step CG-2.6 — Super admin plan controls [V1]
- **Delivers B-9.4** unchanged.
- **Done when**: B-9.4's done-when.

### Step CG-2.7 — Pricing and segment pages [V1]
- **Delivers B-12.2**: `/for-creators`, `/for-business` and a pricing table generated from
  `config/plans.php`. Copy leads with what we win on (AI that knows your brand, insights in plain
  words, nine networks). An optional "How we compare" table only states facts checked that day,
  with an "as of" date (Principle 6).
- **Done when**: B-12.2's done-when, plus a test that the pricing table shows every plan in
  `config/plans.php`.

---

## Phase CG-3 — Link in bio [V1]

Goal: Alima owns the creator's bio link, as Planoly, Later, Buffer and Linktree do. It becomes
the surface the store, email capture and media kit plug into.

### Step CG-3.1 — Bio page model and public page [V1]
- **Delivers B-11.3**, promoted from Stretch to V1.
- `BioPage` (`bio_pages`: `org_id`, `client_id` unique, `handle` unique, `title`, `bio`,
  `theme` (brand tokens only, no raw hex), `published_at`) and `BioBlock` (`bio_blocks`:
  `bio_page_id`, `type` enum `link|latest_posts|media_kit|product|email_capture|booking`,
  `position`, `settings` json, `is_visible`). Only `link`, `latest_posts` and `media_kit` are live
  in this step; the others arrive with CG-4/CG-6.
- Public `GET /@{handle}` (guest, `throttle:public-token`), rendering only published content. Reserved
  handles list (`admin`, `api`, `kit`, `review`, route prefixes).
- **Done when**: tests cover handle uniqueness and reserved words, that drafts/unpublished posts never
  render, and that a deleted brand's page 404s.

### Step CG-3.2 — Bio page editor [V1]
- `GET/PUT /bio` in the business shell: add, reorder (drag), hide and edit blocks, with a live phone
  preview (reuse the grid planner's phone frame). Themes are presets derived from the Brand Brain
  palette.
- AI draft (Stretch inside this step): "Draft my page" fills the title, bio and suggested links from
  the Brand Brain. The draft isn't persisted until the owner saves (same pattern as
  `DraftBrandProfileAction`).
- **Done when**: tests cover block CRUD + reorder in a transaction, scoping to the primary brand,
  and the AI draft never saving on its own.

### Step CG-3.3 — Clicks and insights [V1]
- Count views and per-block clicks (I-10.4 link tracking, or a minimal `bio_events` table with
  daily rollups; no IPs stored raw). Show top links and views on `/insights`.
- **Done when**: tests cover click counting, bot/self-click exclusion rules, and the insights section
  rendering with and without data.

---

## Phase CG-4 — Creator store [V1]

Goal: creators sell from their bio page at a 0% platform fee, matching Planoly's Creator Store on
digital products and bookings. New module doc: `.junie/modules/creator-store.md` (add the README row
in the same change; `ModuleDocsTest` enforces it).

### Step CG-4.1 — `PaymentProvider` contract and Stripe Connect onboarding [V1]
- `App\Contracts\PaymentProvider` (`createConnectedAccount`, `onboardingLink`, `accountStatus`,
  `createCheckout`, `capture`, `cancelAuthorisation`, `refund`), bound to
  `App\Services\Payments\StripeConnectProvider` (stripe-php, **not** Cashier) and `NullPaymentProvider`.
- `payment_accounts` (`org_id` unique, `provider`, `external_account_id`, `charges_enabled`,
  `payouts_enabled`, `requirements_due` json, `country`, `default_currency`). Express onboarding
  from `/store/setup`. Status refreshes from `account.updated` webhooks.
- Separate webhook endpoint and signing secret from Cashier's. Idempotent handling keyed on the
  Stripe event id.
- **Done when**: signed-fixture webhook tests cover onboarding complete, requirements due and
  disabled; replays are idempotent; no Connect secrets appear in props or logs.

### Step CG-4.2 — Products, checkout and orders [V1]
- `products` (`org_id`, `client_id`, `type` enum `download|booking|tip|qa`, `title`, `description`,
  `price_minor`, `currency`, `status`, `cover_asset_id`), `orders` (`product_id`, buyer email/name,
  `amount_minor`, `currency`, `status` state machine `pending → paid → fulfilled | refunded |
  cancelled`, `external_payment_id`), `order_events` (audit).
- `CreateCheckoutAction` → Stripe Checkout on the connected account (direct charge, application fee
  0 per Principle 3), success/cancel pages on the bio domain. `checkout.session.completed` →
  `MarkOrderPaidAction` in a transaction.
- A `product` block on the bio page (CG-3.1).
- **Done when**: tests cover the state machine (illegal transitions rejected), webhook-driven paid
  status, double-delivery of the same webhook, currency mismatches, and org scoping.

### Step CG-4.3 — Digital downloads [V1]
- Files via `AssetStorage` (S3, private). After payment, `FulfilDownloadAction` emails a receipt with
  a signed, expiring link (and a re-send page keyed by order + email). Download count and expiry are
  configurable per product.
- **Done when**: tests cover link expiry, the download limit, that an unpaid or refunded order gets
  no link, and that the file URL is never public.

### Step CG-4.4 — Coupons and checkout policies [V1]
- `coupons` (code, percent/amount, expiry, usage limit, product scope) applied in
  `CreateCheckoutAction`. Store-level terms, privacy and refund text shown on checkout.
- **Done when**: tests cover expiry, usage limits under concurrent redemption (row lock), and
  product scoping.

### Step CG-4.5 — Bookings, tips and paid Q&A [V1]
- **Bookings:** availability windows + buffers on the product, slots generated in the brand's
  timezone (reuse `PostingGoalGapService`-style timezone maths), conflict check against existing
  bookings, buyer reschedule/cancel link, `.ics` attachment.
- **Tips:** one-off amount with an optional message.
- **Paid Q&A:** manual capture. The card is authorised at checkout and captured only when the
  creator answers. The authorisation is cancelled if they don't answer within the window, which is
  set shorter than the card authorisation lifetime (Stripe's is typically 7 days). A scheduled
  command expires stale ones.
- **Done when**: tests cover slot generation across a DST change, double-booking prevention,
  capture-on-answer, and expiry cancelling the authorisation.

### Step CG-4.6 — Earnings, orders and refunds [V1]
- `/store` in the business shell: products, orders (DataTable), payout status from the connected
  account, and refunds (`RefundOrderAction` → provider → webhook confirms). Business copy only;
  status via `StatusPill`.
- **Done when**: tests cover refund flow and permissions (Owner/Manager only), and that refund
  revokes download links.

### Step CG-4.7 — AI launch campaign from a product [V1]
- The differentiator: `DraftLaunchCampaignAction` takes a product and the Brand Brain and drafts a
  collection (campaign) of launch posts (teaser, launch, reminder, social proof) with captions per
  platform, suggested dates from best times, and optional grid tiles. Everything is a **draft**;
  the owner reviews and schedules (Principle 5). Metered via `ai_usage_logs`.
- **Done when**: tests with a fake provider assert drafts are created unscheduled, guardrails
  (never-say, hashtag ceilings, link warnings) are applied, and the allowance is checked first.

---

## Phase CG-5 — Creator deals & compliance [V1]

Goal: complete the sponsor workflow that only Beacons partly offers, on top of the media kit.

### Step CG-5.1 — Paid partnership disclosure [V1]
- **Delivers B-11.1** unchanged.
- **Done when**: B-11.1's done-when.

### Step CG-5.2 — Brand deals tracker [V1]
- **Delivers B-11.2**, promoted from Stretch to V1. Deals link to posts; deliverable progress fills
  as linked posts publish; opt-in feed to the media kit's "past partners".
- Optional link to CG-4: a deal can raise a Stripe invoice through the connected account (Beacons
  charges 0% on invoices).
- **Done when**: B-11.2's done-when.

### Step CG-5.3 — Media kit rates and enquiries [Stretch]
- Optional rates section on the media kit and an enquiry form that creates a draft deal (CG-5.2).
  Spam-protected (honeypot + throttle).
- **Done when**: tests cover the enquiry creating a draft deal on the right brand and the throttle.

---

## Phase CG-6 — Audience [V1/Stretch]

Goal: capture and nurture buyers and fans, matching Planoly's CRM and Stan's email flows.

### Step CG-6.1 — Buyers and subscribers [V1]
- `audience_contacts` (`org_id`, `client_id`, email, name, source `order|bio_capture|import`,
  `consent_at`, `unsubscribed_at`), with an `email_capture` bio block (double opt-in), CSV import
  (opt-in email sent, never assumed consent), and a contacts list in the business shell.
- **Done when**: tests cover double opt-in, unsubscribe, import never marking consent, and B-13.2
  export/deletion covering contacts.

### Step CG-6.2 — Automated emails [V1]
- Purchase follow-up, abandoned checkout (from `checkout.session.expired`) and welcome, sent through
  Resend with an unsubscribe link and the brand's styling. Queued, idempotent per contact + trigger.
- **Done when**: tests cover each trigger firing once, unsubscribed contacts skipped, and the
  abandoned email cancelled if the order completes.

### Step CG-6.3 — Broadcasts with AI drafts [V1]
- Write a one-off email to a segment (all, buyers of product X). "Draft with AI" fills it from the
  Brand Brain. The owner sends; AI never sends.
- **Done when**: tests cover segment selection, the send being human-initiated only, and metering.

### Step CG-6.4 — Instagram auto-DM [Stretch]
- Comment-keyword → DM with a link (Planoly/Stan feature). Depends on **Phase 5** (own Meta app) and
  Meta's messaging permissions. Opt-in per post, rate-limited, and logged.
- **Done when**: blocked until Phase 5; then tests cover keyword match, one DM per user per post, and
  the opt-in.

---

## Phase CG-7 — Merch, courses & memberships [Stretch]

### Step CG-7.1 — Print-on-demand merch [Stretch]
- `App\Contracts\FulfilmentProvider` bound to a Printful implementation. Product type `merch`,
  catalogue picker, design upload, price editor showing profit after the manufacturing cost, order
  hand-off on payment, tracking emails, Printful's returns policy shown at checkout.
- **Done when**: contract tests with a fake provider cover order hand-off, a failed hand-off
  (refund + alert), and the profit calculation.

### Step CG-7.2 — Memberships and courses [Stretch]
- Recurring products (Stripe subscriptions on the connected account) gating a members area of
  posts/files/videos. **Refunds and cancellations revoke access** (Planoly's known gap).
- **Done when**: tests cover access granted on payment, revoked on refund/cancel/failed renewal.

---

## Phase CG-8 — Mobile reach [Stretch]

### Step CG-8.1 — App-store presence decision [Stretch]
- Use CG-1.3 push delivery data and B-12.4 funnel metrics to decide between: keeping the PWA only,
  listing it in Google Play as a Trusted Web Activity, or a native app. Record the decision in the
  module doc.
- **Done when**: the decision and its evidence are in `business-workspaces.md`.

---

## Phase CG-9 — Launch & watch [V1]

### Step CG-9.1 — Funnel metrics [V1]
- **Delivers B-12.4**, adding: trial start → paid conversion, time to first published post, % of
  creators with a published bio page, store GMV (from orders, not Stripe balance), churn by plan.
- **Done when**: B-12.4's done-when plus fixtures for each new metric.

### Step CG-9.2 — Quarterly competitor review [V1]
- A scheduled reminder (super admin notification) each quarter to re-check competitor pricing
  and refresh the gap analysis docs. The "How we compare" table (CG-2.7) is updated or removed.
- **Done when**: the command and its test exist; the first review date is in the module doc.

---

## 3. Sequencing & MVP cut

```
CG-0 ──► CG-1 (switch on) ──► CG-2 (charge) ──► public solo launch
                                   │
                                   ├──► CG-3 (link in bio) ──► CG-4 (store) ──► CG-6 (audience)
                                   └──► CG-5 (deals)
Stretch: CG-3.2 AI draft, CG-5.3, CG-6.4, CG-7, CG-8
```

**MVP** (we can charge solo users without trailing Planoly/Buffer on what we already built): CG-0,
CG-1.1–1.3, CG-2.1–2.5.

**V1** (head-to-head with Planoly/Later for creators): CG-1.4, CG-2.6–2.7, CG-3, CG-4, CG-5.1–5.2,
CG-6.1–6.3, CG-9.

Rough sizing: CG-1 is mostly composition over existing Actions (small). CG-2 is medium and mostly
specified already in B-9. CG-4 is the largest and riskiest (money, webhooks, refunds). Give
CG-4.1–4.2 a dedicated review with `graphy-convention-reviewer`, and run the full agency suite after
CG-1 and CG-2.

---

## 4. Module docs touched

- `business-workspaces.md`: every phase (shell routes, nav, copy, decisions).
- `inbox.md` (CG-1.1), `grid-planner.md` (CG-1.2), `notifications.md` (CG-1.3).
- New: `creator-store.md` (CG-4, CG-7), `bio-pages.md` (CG-3), `audience.md` (CG-6). Each needs its
  README row in the same change (`tests/Unit/ModuleDocsTest.php`).
- `analytics.md` (CG-3.3, CG-9.1), `super-admin.md` (CG-2.6, CG-9).

---

## 5. Open questions

1. **Prices.** Confirm £15 / £29 (CG-0.1), and whether the US price is $19 / $37 or set at parity.
2. **Tax and VAT.** Creator-handled with Stripe Tax, or a merchant-of-record partner (CG-0.2)?
3. **Stripe entity.** UK or UAE platform account, and which countries creators can onboard from?
4. **Free plan selling.** Allowed with a fee, or paid plans only?
5. **Handle namespace.** `alima.bio/@handle`, a subpath on the app domain, or custom domains (a
   paid-plan feature at Stan/Beacons/Linktree)?
6. **Inbox on Free.** Buffer includes it on every plan. Do we include it on Free, or use it to
   drive upgrades?
7. **Instagram auto-DM** is blocked on Phase 5. Is Phase 5 worth pulling forward for it?

---

## 6. Exit criteria for this plan

- A solo user can start a free trial, pay by card, and use the grid planner, inbox and push
  reminders from the business shell, with no agency vocabulary.
- A creator can publish a bio page, sell a download or a booking at a 0% platform fee, get paid to
  their own Stripe account, refund, and turn a product into an AI-drafted launch campaign they
  approve.
- Every new feature is gated through `Entitlements` with a test per limit.
- Cashier subscriptions and Connect sales share no tables, webhooks or secrets.
- Agency behaviour is unchanged (full agency regression suite green after every phase).
- Module docs describe what was built, and the gap analysis docs are refreshed after launch.
