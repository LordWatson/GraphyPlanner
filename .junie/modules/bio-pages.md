# Link in Bio (Bio Pages)

## Purpose
Phase CG-3 of `.junie/plans/business-competitor-gap.md` (delivers B-11.3, promoted to V1). One public
page per brand — the single link a creator puts in every profile bio — owned by Alima rather than
Linktree/Planoly/Later. It is the surface the creator store (CG-4), email capture (CG-6) and the
media kit plug into, so its block model is built to take new block types without a migration.

Live today: **link**, **latest posts**, **media kit** and (since CG-4.2) **product** blocks, a
per-day view/click rollup, and the numbers on `/insights`.

## Models / Tables
- `BioPage` (`bio_pages`): `org_id`, `client_id` **unique** (one page per primary brand), `handle`
  **globally unique** (the public namespace, lowercase, 3–32 chars), `title`, `bio`, `theme`
  (`BioTheme` — a brand **token name**, never a raw hex), `published_at` (null = the page 404s).
  Index `(org_id, published_at)`. No `OrganizationScope`, like `MediaKit`: the owner only reaches it
  through the workspace brand and the public route looks it up by handle.
- `BioBlock` (`bio_blocks`): `bio_page_id`, `type` (`BioBlockType`), `position` (0-based, rewritten
  on every save), `settings` json (only `label`, `url`, `limit` are stored — the Action whitelists
  them), `is_visible`. Index `(bio_page_id, position)`.
- `bio_events` (no model — written and read with the query builder): `bio_page_id`,
  `bio_block_id` **nullable** (null = a page view), `date`, `count`. Unique
  `(bio_page_id, bio_block_id, date)`. **No IP, user agent, session or any other visitor data is
  stored.** Both engines treat NULLs as distinct in a unique index, so view rows can race into
  duplicates; every read SUMs, so the total is right either way.

## Enums
- `App\Enums\BioBlockType`: `link`, `latest_posts`, `media_kit`, `product`, `email_capture`,
  `booking`. `live()` is what renders today — the four including `product` (CG-4.2); `email_capture`
  is rejected by validation until CG-6, so a page can never hold a block nothing renders. There is
  no `booking` block: a booking is a `ProductType`, sold through a `product` block like the rest of
  the store.
- `App\Enums\BioTheme`: `cream`, `ink`, `lime`, `electric`, `lilac` — each an approved brand pairing
  (brand guidelines §3.3), so a page can't be made unreadable.
- `App\Enums\Feature::BioPage` — the plan gate (Creator Pro; see `plans.md`).

## Actions / Services / Contracts
- `App\Actions\Business\SaveBioPageAction`: the whole page in one `DB::transaction()` — details plus
  the full ordered block list. Add/edit/hide/reorder/remove are the same operation, so a page is
  never half-reordered. Checks `Entitlements::ensure(Feature::BioPage)` **only when publishing**.
  A block `id` that isn't this page's is treated as new, so one brand can't rewrite another's block.
- `App\Actions\Business\BuildBioPageAction`: the visitor payload, shared by the public page and the
  owner's preview. Only visible blocks, only **published** posts, and a media-kit block only while
  that public link is on. `destination()` resolves a clickable block's real URL server-side.
- `App\Actions\Business\RecordBioEventAction`: counts a view or click into the day's rollup. Skips
  bots/unfurlers (user-agent markers, and an empty user agent) and any signed-in member of the
  page's own org, so the owner's own checking isn't most of the numbers.
- `App\Actions\Business\GetBioInsightsAction`: 30-day views, taps and top five links for `/insights`.
  Returns null when the brand has no page, so the section disappears instead of showing zeroes.
- No new contract: there is one implementation of each and nothing external to swap.

## Routes / Controllers
- `GET /@{handle}` → `BioPageController@show` — the public page (guest, `throttle:public-token`).
  404s when the page is unpublished or the brand is gone.
- `GET /@{handle}/go/{block}` → `BioPageController@click` — counts the tap, then `redirect()->away()`
  to the owner's own URL. The page never carries the destination, so the count can't be skipped.
- `GET /bio` → `Business\BioPageController@edit` — the editor (`workspace:business`).
- `PUT /bio` → `Business\BioPageController@update` — one save (`SaveBioPageRequest`).
- Frontend: `resources/js/components/bio/bio-page-view.tsx` (shared renderer),
  `pages/public/bio.tsx`, `pages/business/bio.tsx` (editor + live phone preview). Nav: sub-items of
  the **Brand** chapter (`/bio`, `/media-kit`) — no new chapter number.

## Business Rules
- **Published or nothing.** `published_at` is the only thing that makes `/@{handle}` resolve;
  taking the page offline clears it and every link stops working at once. Re-publishing keeps the
  original date.
- **Handles** are globally unique, lowercased and trimmed on the way in, must match
  `^[a-z0-9][a-z0-9._-]*[a-z0-9]$`, and can't be one of `BioPage::RESERVED_HANDLES` (`admin`, `api`,
  `kit`, route prefixes, …). The `@` prefix means collisions with app routes are impossible anyway;
  the list exists because a handle is a public identity.
- **Publishing is a plan gate, editing isn't.** A Free workspace can build its page and see exactly
  what upgrading would put online; publishing raises the friendly `PlanLimitReachedException`.
- **Privacy.** Hidden blocks, draft/scheduled posts and a media kit whose link is off never reach
  the public payload. Visitor analytics are counts per day only.
- **Themes are presets**, stored as token names; no raw colour ever reaches the database.
- **A `product` block resolves live, not at save time** (`BuildBioPageAction::destination()`): it
  points at `/@{handle}/p/{product}` only while that product is `live` and belongs to this brand, so
  archiving or drafting a product takes it off the page by itself. See `creator-store.md`.
- Max 20 blocks (`SaveBioPageRequest::MAX_BLOCKS`); link URLs must be `http`/`https`.

## Tests
- `tests/Feature/Business/BioPageTest.php`: save + publish + public render; handle uniqueness,
  reserved words and normalisation; unpublished and deleted-brand 404s; hidden blocks and
  unpublished posts never render; one save that adds/edits/reorders/hides/removes; another brand's
  block id is untouched; publishing gated by plan while drafting isn't; an agency 404s on the editor;
  clicks redirect and roll up (and 404 for a hidden/foreign block); views counted while bots and the
  owner are not; the insights payload with and without data.

## Known Gaps / Open Questions
- **Reorder is up/down buttons, not drag.** `@dnd-kit/sortable` isn't installed and the shell is
  phone-first (HTML5 drag doesn't work on touch); arrows are also the keyboard-accessible path. Add
  drag only if a dependency lands for another reason.
- **CG-3.2's AI "Draft my page"** (Stretch in the plan) isn't built. It would follow
  `DraftBrandProfileAction`: draft, never persist until the owner saves.
- **Clicks inside a `latest_posts` block aren't counted** — those links go straight to the network.
  Only `link` and `media_kit` blocks route through `/go/{block}`.
- **Handle namespace** is a path on the app domain (`/@handle`). `alima.bio/@handle` and custom
  domains are still open (plan §5.5).
- **I-10.4 link tracking** doesn't exist, so CG-3.3 built the minimum rollup it needed. If I-10.4
  lands, `bio_events` should fold into it rather than being kept in parallel.
- Deleting a brand cascades the page, blocks and rollups; B-13.2 (data export/deletion) must list
  `bio_pages`/`bio_blocks`/`bio_events` when it covers the new tables.

## Gotchas
- **Never name a page prop `preview`.** `HandleInertiaRequests` shares `preview` (true on
  `/preview/*`); an Inertia page prop of the same name shadows it, and `usePreviewGuard` then
  cancels every link on the page with "That page isn't part of the preview." while `PreviewBanner`
  claims the data is frozen sample data. The owner's live preview payload is `publicPreview` on both
  `/bio` and `/media-kit`, and both readers of the shared prop now test `=== true`.
  Covered by `BioPageTest::test_the_editor_never_shadows_the_shared_preview_prop`.

## Changelog
- 2026-11-06: Phase CG-4 switched the **product** block on (`settings.product_id`, resolved live
  through the counted `/go/{block}` route) and the editor now picks from the brand's live products.
- 2026-11-06: Phase CG-3 (CG-3.1–3.3) built: `bio_pages`/`bio_blocks`/`bio_events`, the public
  `/@{handle}` page and counted click-through, the one-save editor with a live phone preview, the
  plan gate on publishing, and the bio section on `/insights`.
- 2026-11-06: Fixed the editor shadowing the shared `preview` prop (page locked into preview mode,
  every link blocked). Renamed to `publicPreview` here and on `/media-kit`, which had carried the
  same bug since B-8.4, and tightened `usePreviewGuard`/`PreviewBanner` to `preview === true`.
