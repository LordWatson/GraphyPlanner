# Brand Deals & Paid Partnerships

## Purpose

Phase CG-5 of `.junie/plans/business-competitor-gap.md` (delivers **B-11.1** unchanged and **B-11.2**,
promoted Stretch → V1). The sponsor side of a creator's work, in two halves:

- **Disclosure (CG-5.1)** — a post can declare itself a paid partnership, and the pre-schedule
  checklist then **refuses to let it go out** until every channel's published caption actually says
  so. An undisclosed ad is the creator's legal problem (ASA/FTC), so this is a blocker, not a hint.
- **The tracker (CG-5.2 / CG-5.3)** — who they're working with, what they owe, what it pays, and
  what has actually been published, with an optional enquiry form on the media kit that drops new
  work straight into the list.

It completes the sponsor workflow that only Beacons partly offers, on top of the media kit (B-8.4).

## Models / Tables

- `posts.is_paid_partnership` (bool, default false), `posts.partner_name`, `posts.partner_handle`
  (`2026_11_25_100100_add_paid_partnership_to_posts.php`). The declaration is the owner's; the
  checklist enforces the consequence.
- `posts.brand_deal_id` → `brand_deals`, `nullOnDelete` (`…_100200_create_brand_deals_table.php`):
  which deal a post delivers. Deleting a deal leaves the post's own disclosure intact.
- `BrandDeal` (`brand_deals`): `org_id`, `client_id`, `partner_name`, `partner_handle`,
  `status` (`DealStatus`), `fee_minor` + `currency` (minor units, like all money here),
  `deliverables` json (`[{platform, quantity}]`), `starts_on`, `due_on`, `notes`, `contact_email`
  (only set by an enquiry), `show_on_media_kit` (opt-in, **off** by default). Indexes
  `(client_id, status)`, `org_id`, `due_on`. Step GTM-1.2 adds `invoice_id` → `invoices`
  (`nullOnDelete`, `2026_11_30_100100_add_invoice_to_brand_deals.php`): which invoice billed this
  deal, and the lock that stops it being billed twice. **Not fillable.**
- `media_kits.rates` json (`[{label, amount_minor}]`) and `media_kits.enquiries_enabled`
  (`…_100300_add_rates_and_enquiries_to_media_kits.php`), both opt-in and both off/empty by default.

**Deliverable progress is never stored.** It is counted from the deal's own published posts, so the
tracker cannot claim a post went out when it didn't (or lag one that did).

## Enums

- `App\Enums\DealStatus`: `enquiry`, `pitched`, `agreed`, `delivered`, `paid`, `declined`.
  Deliberately **not** a state machine — real deals go backwards or skip steps, so the owner sets
  whichever status is true and nothing refuses a move. `enquiry` is the only one the app sets itself
  (CG-5.3), so an enquiry can never read as work the creator agreed to. `isOpen()` drives the
  overdue flag; `isPastPartner()` gates the media kit.
- `App\Enums\Feature::BrandDeals` — the plan gate (Creator Pro; see `plans.md`).

## Support / Actions

- `App\Support\Posts\PartnershipDisclosure` — the single rule. `MARKERS` is the accepted wording
  (`#ad`, `#sponsored`, `#paidpartnership`, `#gifted`, "paid partnership", "sponsored by", …),
  matched case-insensitively; `SUGGESTED` is what the editor and the checklist detail show, so the
  words the owner reads are the words that are enforced. `isRequired()` casts the column, because a
  post the checklist sees before it is reloaded (grid production builds posts in memory) has no
  value for it yet.
- `App\Actions\Posts\EvaluatePostChecklistAction` — new **hard** item `partnership_disclosure`,
  evaluated per channel against `PostTarget::publishedCaption()`.
- `App\Actions\Deals\SaveBrandDealAction` — create/update in one Action, gated on
  `Feature::BrandDeals`, deliverables normalised to one row per real platform.
- `App\Actions\Deals\GetBrandDealsAction` — the page payload with derived progress (one grouped
  query for every deal), `partnersFor()` for the media kit, and `linkOptionsFor()` for the post
  editor's deal picker.
- `App\Actions\Deals\IssueDealInvoiceAction` (Step GTM-1.2) — one deal → one `Invoice`, composed
  from `CreateInvoiceAction` + `SendInvoiceAction` so the PDF, the email and the reminder pipeline
  are the agency ones. Gated on `Feature::BrandDeals`; the invoice is created and linked in a
  transaction and the email is sent outside it, so a mail failure never undoes a raised invoice.
- `App\Actions\Deals\RecordDealEnquiryAction` — an enquiry becomes a `DealStatus::Enquiry` deal with
  the sponsor's email and message. Nothing is sent back on the creator's behalf. **Not** plan-gated:
  the form only exists on a kit whose owner switched it on, and losing real work to a lapsed plan
  would be the wrong trade.
- `App\Actions\Business\UpdateMediaKitAction` — now saves the bio, the rate card and the enquiry
  switch together.
- `App\Actions\Business\BuildMediaKitAction` — fills B-8.4's previously-empty "past partners", plus
  `rates`, `currency` and `accepts_enquiries`.

## Routes / Controllers

Business shell (`workspace:business`; writes need the brand-**update** ability, Owner/Manager):

- `GET /deals` → `Business\BrandDealController@index`
- `POST /deals`, `PUT /deals/{deal}`, `DELETE /deals/{deal}` → same controller
- `POST /deals/{deal}/invoice` → `invoice` (Step GTM-1.2): raise, PDF and email the sponsor's
  invoice for this deal
- `PUT /media-kit` (B-8.4) now also carries `rates` and `enquiries_enabled`

Public:

- `POST /kit/{token}/enquire` → `MediaKitShareController@enquire` — guest, inside the
  `throttle:public-token` group **and** its own `throttle:6,1`, honeypot (`website`), and only
  answered while the owner has the form on. The response is identical either way, so the form can't
  be used to probe which kits are live.

The post editor's own fields ride on the existing `posts.update` / `posts.store` requests
(`is_paid_partnership`, `partner_name`, `partner_handle`, `brand_deal_id` — the deal scoped to the
post's own brand).

Frontend: `pages/business/deals.tsx`, the Paid-partnership panel in
`components/post-editor/editor/settings-section.tsx` (+ state in `editor-state.ts`, submission in
`editor-form-fields.tsx`, `partnership_disclosure` → the Settings step in `checkSection`), the rate
card and enquiry switch in `pages/business/media-kit.tsx`, the rates/partners sections in
`components/media-kit/media-kit-view.tsx`, the enquiry form in `pages/share/media-kit.tsx`, and
rates + past partners in `resources/views/pdf/media-kit.blade.php`. Nav: **Deals** is a sub-item of
the **Brand** chapter (prefix `/deals`) — no new chapter number. `StatusPill` kind `deal`.

## Business Rules

- **Disclosure is per channel, and per platform for free.** The check reads each target's
  _published_ caption, which is composed per platform — so on a platform that strips hashtags
  (Google Business) `#ad` in the hashtag field doesn't count and the owner must write it into the
  text, while on Instagram either place works.
- **We never guess that a post is an ad.** Only the owner's declaration triggers the requirement: a
  wrong guess would either block an honest post or imply a declaration nobody made.
- **Wording is generous, not prescriptive.** The law asks for a clear disclosure; the creator's own
  phrasing is theirs to choose. We only refuse silence.
- **The tracker is a plan gate**; the page renders without it and explains the upgrade, as `/bio`
  and `/store` do.
- **"Past partners" is doubly opt-in**: the deal must be `delivered`/`paid` **and**
  `show_on_media_kit`. A sponsor relationship isn't ours to publish.
- **An enquirer's email and message never reach the public kit** — only the creator's tracker.
- **Deleting a deal keeps its posts.** They lose the link (and stop counting) but keep their own
  disclosure, partner name and handle.
- **A deal is invoiced once, and only with a fee and an address** (Step GTM-1.2). `invoice_id` is
  the lock, and it is not fillable — only `IssueDealInvoiceAction` sets it. Without
  `contact_email` the invoice pipeline would fall back to the brand's own billing contact, i.e. the
  creator would email themselves, so a deal with no sponsor address is refused rather than guessed
  at. Deleting the invoice frees the deal to be billed again (`nullOnDelete`).
- Progress is capped at what was agreed (3 published against 2 agreed reads `2/2`).

## Tests

- `tests/Feature/Posts/PaidPartnershipTest.php` — an ordinary post is never asked to disclose; a
  declared one without a disclosure fails the checklist (hard, not soft) **and** is refused by the
  transition endpoint; a disclosure in the caption or in the hashtags passes on Instagram; a
  hashtag-only disclosure fails on Google Business and passes once written into the caption; every
  channel has to disclose, not just one; the fields save and the checklist clears.
- `tests/Feature/Business/BrandDealTest.php` — create/update/remove; the Free gate; a Designer
  can't write; another brand's deal 404s; the page lists only this brand's; progress counts only
  published linked posts on the agreed platform and never exceeds the agreed count; the media kit
  names only opted-in deals that happened; the rate card saves and a label-less rate is refused;
  an enquiry creates a draft deal on the right brand with the contact email; an enquiry is ignored
  when the form is off or the honeypot is filled, and 404s on a revoked link; the form is throttled;
  removing a deal leaves its posts alone; a deal with a fee and an address invoices once (and the
  second attempt is refused), a deal missing either cannot be invoiced and leaves no invoice behind,
  and another brand's deal 404s before the plan gate is reached (GTM-1.2).

## Known Gaps / Open Questions

- **No branded-content flags at the platform.** Instagram's "Paid partnership" label and TikTok's
  disclosure toggle are platform settings Upload-Post doesn't expose, so disclosure is caption-based
  only. If an adapter gains the flag, set it _and_ keep the caption rule.
- **A deal invoice is our invoice, not a Stripe-hosted one** (Step GTM-1.2). `IssueDealInvoiceAction`
  raises a normal `Invoice` for the deal's fee, PDFs it and emails the sponsor through the existing
  pipeline (so reminders, payment reporting and the ledger all work on it). CG-5.2's wording said
  "a Stripe invoice through the connected account" — that is **not** what this is: the sponsor pays
  by whatever means the invoice says, Stripe is not involved, and nothing here charges a card.
- **A business workspace still has no invoice pages** (Step B-1.4 made client invoicing agency-only
  and the whole `/invoices` block 404s for a business org). So a creator can raise and send a deal
  invoice, and can see its number and status on the deal row, but cannot open, edit, remind on or
  mark it paid from the UI — that needs a business-shell invoice surface, which is deliberately not
  in GTM-1.2.
- **Deliverables are counts per platform, not named posts.** "Two reels and a story" is two rows
  (`instagram 2`, `instagram 1`) collapsed to one — format-level deliverables would need the
  deliverable to carry a format.
- **A linked post counts on every platform it published to**, so one cross-posted post can fill two
  platforms' deliverables. That is usually what the creator means, but it is not enforced either way.
- **No reminders.** `due_on` drives an overdue style on the page; nothing emails or pushes about it
  (unlike campaign deliverables, which have `campaigns:send-due-reminders`).
- **Enquiries have no spam learning.** Honeypot + throttle only; a determined bot can still create
  draft deals, which the owner deletes. No captcha, no blocklist, no notification of a new enquiry
  (it waits on the page) — a notification is the obvious next step.
- **B-13.2 (export/deletion)** must list `brand_deals` when it covers the new tables.

## Gotchas

- **The disclosure item is intentionally hard.** Making it `soft` would quietly turn a legal
  requirement into a style note; if it has to be bypassed, that belongs in an explicit override
  (like `overrideApproval()`), not a softened check.
- **`PartnershipDisclosure::MARKERS` and the editor's hint must stay in step.** The panel shows
  `SUGGESTED`; if a marker is added or removed, the copy follows from the same class.
- A deal's `fee_minor` is **minor units**; the page types whole currency and converts. Same contract
  as the creator store (`creator-store.md`).

## Changelog

- 2026-11-07: Step GTM-1.2 — `brand_deals.invoice_id` + `IssueDealInvoiceAction`: one deal becomes
  one invoice on the existing invoice pipeline, emailed to the sponsor's `contact_email`. Refused
  without a fee or an address, and refused a second time. The deal's own `DealStatus` is **not**
  moved — it isn't a state machine and billing isn't delivery.
- 2026-11-06: Phase CG-5 built. CG-5.1 (B-11.1): `is_paid_partnership`/`partner_name`/
  `partner_handle`, `PartnershipDisclosure`, and the hard `partnership_disclosure` checklist item
  that blocks scheduling per channel. CG-5.2 (B-11.2): `brand_deals`, `posts.brand_deal_id`, the
  `/deals` tracker with derived progress, and the media kit's now-filled "past partners". CG-5.3:
  the optional rate card and the throttled, honeypot-protected enquiry form that creates a draft
  deal.
