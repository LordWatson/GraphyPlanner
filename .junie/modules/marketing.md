# Marketing Pages (Homepage)

## Purpose

Public, unauthenticated marketing surface used to introduce Alima Planner to prospective users before
they sign up. As of the Nov 2026 redesign this is a **single long homepage** (`/`) that absorbs the
former About content: hero → interactive product demo → networks marquee → before/after slider →
Brand Brain → time calculator → feature bento → audiences → proof → pricing → FAQ → final CTA →
footer. `/about` now redirects to `/`.

## Models / Tables

- None — these pages render static/derived content only, no persisted data.

## Actions / Services / Contracts

- None — plain Inertia routes, no controller logic.

## Routes / Controllers

- `GET /` → `BioPageController@home` (`home` route name); on the app host it renders `welcome` (the
  full redesigned homepage), on a verified custom bio-page domain it renders that creator's bio page.
- `GET /about` → `Route::redirect('about', '/')` (`about` route name kept) — the standalone About
  page was retired into the homepage; the name is preserved so existing links (`MarketingShell`
  footer, Wayfinder `about()`) keep resolving.

## Business Rules

- The homepage renders outside `AppLayout` (see `resources/js/app.tsx` `layout()` resolver —
  `welcome` maps to `null`), carrying its own public header/footer rather than the internal sidebar.
- The page is composed from section components in `resources/js/components/marketing/home/`:
  `product-demo.tsx` (the big interactive 7-screen demo with auto-tour, approve/confetti, drawer),
  `chaos-calm.tsx` (before/after drag slider), `brand-brain.tsx` (pillar/network AI-caption
  generator), `time-calculator.tsx` (4-slider ROI estimate), `feature-bento.tsx` (bento with a
  working client-portal approve, currency toggle, command-palette typing), `audiences.tsx` (tabs),
  `pricing.tsx` (monthly/yearly toggle), `faq.tsx` (accordion). `welcome.tsx` holds the header
  (sticky + mobile menu + rotating hero word + floating toasts), networks marquee, proof,
  final CTA (with `<Peek>`), footer and mobile sticky bar, and orchestrates the sections.
- All interactivity is client-side React `useState`; no new dependencies. Bespoke marketing motion
  lives in `hm-*` utility classes in `resources/css/app.css` (keyframes guarded by
  `prefers-reduced-motion`). Colours are the brand tokens (used as `var(--…)` in computed inline
  styles, or Tailwind token classes). This is the one surface that deliberately exceeds the brand
  book's conservative motion rules (§11) — confetti, marquee, floating toasts, shimmer, typing.
- Content mirrors the real product surface area (clients/brands/campaigns, shared calendar, approval
  workflow, client review portal, AI-assisted captions/ideas/images, publishing to nine networks,
  multi-currency invoicing, client health). Illustrative vignettes use fictional client names, not
  live data.
- **Pricing is config-driven:** `home/pricing.tsx` receives the `pricing` prop (same `PricingData`
  shape and `PlanCatalog::publicPricing()` source as the `/pricing` page, passed from
  `BioPageController@home`) and renders the three agency tiers — `agency_starter`, `agency_standard`,
  `agency_scale` — with each plan's own name, price and `highlights` straight from config, so nothing
  drifts from `config/plans.php`. Annual shows the per-month equivalent (annual ÷ 12).
- **Illustrative placeholders to replace with real data:** the 4th proof stat ("6 hrs a week back
  on approvals") and the testimonial (Maya Karim / Studio Kite — the demo's own fictional personas)
  in `welcome.tsx`'s proof section are examples, not verified figures or a genuine customer quote.
  Swap them for real, attributable data before launch.

## Tests

- `tests/Feature/ExampleTest.php` — `home` returns `200 OK`; `about` returns a redirect to `home`.
- `tests/Feature/Business/BioPageDomainTest.php` — asserts `/` renders `welcome` on the app host.

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

- 2026-11-10: Homepage polish pass — restored the feature-bento hover effects that the first pass
  dropped (`hm-tile` card lift plus per-tile reveals: campaign bars fill, grid swatches reshuffle,
  AI-image cards fan out, inbox third reply slides in — defined in `app.css`, hover-only and
  reduced-motion guarded; also applied the card lift to the audience and pricing cards); moved the
  "Drag from chaos to calm" subtext to the right at `lg`; swapped the final-CTA peek to the brand
  mark in Signal (red) with the head framed over the bottom edge (wrapper does the bleed, `hm-peek`
  the hover-rise); and gave the hero's "Play with the demo" its play-circle icon.
- 2026-11-10: Homepage redesign — `welcome.tsx` rebuilt as a single long, interactive marketing page
  from the design canvas (hero with rotating word + floating toasts, interactive product demo,
  networks marquee, before/after slider, Brand Brain, time calculator, feature bento, audiences,
  proof, pricing, FAQ, final CTA, footer, mobile sticky bar). New section components under
  `components/marketing/home/`; bespoke `hm-*` motion keyframes added to `resources/css/app.css`
  (reduced-motion guarded). `/about` retired to a redirect to `/` (route name kept); `about.tsx` and
  the now-orphaned `components/marketing/campaigns-section.tsx` removed. `ExampleTest` updated to
  assert the `/about` redirect. Pricing/proof/testimonial carry marked placeholders pending real
  values. Note: this page intentionally diverges from the brand book's conservative motion / one-
  graphic rules — animations and graphics were requested for the front page.
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
