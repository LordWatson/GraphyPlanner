# Marketing Pages (Welcome & About)

## Purpose

Public, unauthenticated marketing surfaces used to introduce Alima Planner to prospective users before
they sign up: the landing page (`/`) and the About page (`/about`), which details the product's
feature set to help visitors decide whether to register.

## Models / Tables

- None — these pages render static/derived content only, no persisted data.

## Actions / Services / Contracts

- None — plain Inertia routes, no controller logic.

## Routes / Controllers

- `GET /` → `Route::inertia('/', 'welcome')` (`home` route name) — hero, headline feature grid, CTA.
- `GET /about` → `Route::inertia('about', 'about')` (`about` route name) — expanded feature pillars,
  "how it works" steps, and a closing CTA section. Linked from the welcome page header/footer.

## Business Rules

- Both pages render outside `AppLayout` (see `resources/js/app.tsx` `layout()` resolver — `welcome`
  and `about` both map to `null`), since they share the public hero shell rather than the internal
  sidebar layout.
- Content mirrors the real product surface area (clients/brands/campaigns, shared calendar, approval
  workflow, client review portal, AI-assisted captions/ideas/trends/images, pluggable publishing via
  Upload-Post, multi-currency invoicing, client health) plus a "Recently shipped" strip on `/about` —
  keep in sync with actual shipped features as they evolve.
- Follows the Alima "web in practice" layout (brand book §7.6, `.junie/brand-guidelines.md`): 88px
  header with the 160px horizontal lock-up and one Ink CTA ("Let's talk"), 1280 max width with
  80/40/20px margins, an oversized Poppins SemiBold headline ending in an orange accent word/full
  stop, a lime `spark` CTA ("Let's grow") beside an outline "See the work", and **one** brand graphic
  per page — the lilac panda peek over the welcome hero's bottom rule, a teal/lilac confetti panel
  beside the About closing CTA. Feature icons sit in solid circles; numbered (`01`–`04`) rows sit on
  2px ink rules; the welcome footer is an Ink block with the cream lock-up and tagline. The welcome
  page's "This week" strip is an illustrative vignette built from real `StatusPill`s with fictional
  client names — not live data.

## Tests

- `tests/Feature/ExampleTest.php` — asserts `home` and `about` routes return `200 OK`.

## Known Gaps / Open Questions

- No dedicated Inertia component/props assertions (e.g. `assertInertia`) yet, since the pages carry
  no server-provided props beyond shared `auth` data — add if dynamic marketing props are introduced.

## Step GTM-7.3 — distribution: comparison pages and the partner programme

Worth more than any feature in the GTM plan and cheaper than all of them: every "best social media
tool 2026" result is affiliate-monetised content, and we were absent from it.

- **`GET /compare/{rival}`** — one honest page per competitor, generated from
  `config/competitors.php`. Two rules: **every figure carries one dated "last checked"** (printed on
  the page, and nagged about by `competitors:review-due` so it can't rot), and **the rival's
  strengths come first**. We never claim a competitor lacks something — only what they charge and
  what they say they include. A comparison page that pretends otherwise gets quoted back at you.
- **`GET /partners`** — the affiliate programme's public page. Deliberately plain and not
  self-serve: what the commission is, how attribution works, and an email address.
- **`GET /agents`** (Step GTM-7.4) — agent access claimed on depth. See `mcp.md`.
- Comparison and partner links sit in the marketing shell's **footer**, because an unlinked page
  earns nothing. The four slugs are hard-coded there (so every marketing page doesn't carry the
  competitor config) and `LaunchMechanicsTest` asserts them against config so the two can't drift.

**The referral programme** (`referral_partners`, `referral_payouts`, and
`organizations.referral_partner_id`):

- `CaptureReferralCode` middleware stores `?ref=CODE` from **any** page for 30 days. It validates
  the code against a live partner **before** storing it, so the cookie can only ever hold a real
  code; **first touch wins**, because whoever introduced somebody earned it; and nothing about the
  visitor is stored — one cookie, one code, no row until they sign up.
- Attribution lives on `organizations`, not a join table: the org is already the Cashier billable,
  so it is the thing a commission is owed on, and a separate table would be a second place for the
  same one-to-one fact to disagree with itself. Written inside `RegisterOrganizationAction`'s
  transaction.
- A **payout is a record of money already sent**, not an instruction to send any. What is _owed_ is
  derived when somebody settles up. There is no payment integration, on purpose: paying a partner
  is a bank transfer and a conversation, and automating it before the first partner exists would be
  building the wrong thing twice.
- Super admin: `/super-admin/referrals` (add a partner, see who they sent, record a payout, end a
  partnership). Ending one **keeps every attribution** — the customers they already sent are still
  theirs; the code just stops tracking new ones.

## Changelog

- 2026-10-18: Campaigns enhancement C-9.2 — a Campaigns section on the welcome page (`components/marketing/campaigns-section.tsx`): eyebrow, "Plan it, sign it off, ship it, prove it.", the three points, and a product vignette built from real components (phase band, status pills, readiness, wrap report). No second Signal Orange accent; the hero keeps the page's one.
- 2026-10-17: About page content refresh — pillar copy now mentions calendar drag-to-reschedule,
  Brand Brain/campaign-grounded AI (captions with hashtags, Daily Briefs) and per-client-currency
  invoicing; workflow step 02 mentions AI images; new "Recently shipped" section (AI Image Studio,
  drag-to-reschedule, multi-currency invoicing, ⌘K command palette) between the pillars and "How it
  works". Static copy only — no route or prop changes. Refresh/rotate the "Recently shipped" list as
  features age.
- 2026-10-16: Branded error pages: `resources/views/errors/layout.blade.php` plus 403 / 404 / 419 /
  429 / 500 / 503 views replace Laravel's unstyled defaults — an Ink chapter-opener spread with the
  status code as a giant Lime numeral, a voice-led headline, a Lime CTA (home, or the studio when
  signed in) and the cream peek. Self-contained (inline CSS, `public/brand` artwork, no Vite bundle)
  so they render when the app itself has failed; 403 shows the abort message when one is given.
  `tests/Feature/ErrorPagesTest.php` covers 404, 403-with-message and the signed-in CTA. The About
  page's closing CTA no longer grows tall: `BrandPattern` now fills its box absolutely, so the
  artwork's tall intrinsic ratio never sets a row's height.
- 2026-10-16: Alima rebrand of `/` and `/about` (see Business Rules) — copy moved to the Alima voice;
  no route or prop changes.
- 2026-10-15: "Studio Paper" redesign of `/` and `/about` — no route or prop changes.
- 2026-10-14: Added `/about` page (and route) with feature pillars, workflow steps, and CTA section;
  linked from the welcome page nav/footer.
