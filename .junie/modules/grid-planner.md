# Instagram Grid Planner

## Purpose
Phase 18 (Steps 18.1–18.14). Plan how a client's Instagram profile grid will look before anything
goes out, preview it as followers will see it (on top of the real profile), get the look signed off
internally and by the client, then turn each tile into a real `Post` and schedule the set in the
right order so the profile ends up exactly like the plan — and keep it true afterwards.

**The grid approves the look; the post approves the words.** A grid plan's approval covers tiles,
media, framing and order. Each tile becomes an ordinary `Post` that goes through the normal post
workflow (`PostStatusTransitionMap`, the §6 checklist) for its caption, hashtags and schedule.

## Platform facts (verified 2026-10-20)
- The profile grid shows **3:4 portrait tiles** (since early 2025), three per row. A 3:4 upload
  (1080×1440) shows in full; a 4:5 feed post is centre-cropped top and bottom on the grid; a Reel's
  cover is cropped from 9:16 to 3:4.
- Newest first: the last post published sits top-left. **Pinned posts** (up to 3) sit in front of
  everything and push the grid along.
- Instagram added in-app "Reorder grid" (June 2026; pinned posts and Reels stay fixed). It is not in
  the publishing API, so we rely on publish order; in-app reordering is only the manual fix, and
  the UI says so (order-at-risk banner).
- **Pinned state is not exposed by the Graph API** (checked 2026-10-20: the IG Media fields include
  `media_product_type` — AD / FEED / STORY / REELS — but no pinned flag; the changelog to June 2026
  adds none). So `pinned_count` is a manual plan setting and the preview labels those slots
  "Pinned". Re-check the IG Media reference if Meta adds one.
- The registry's Instagram feed `aspect` is 0.8–1.91, but 3:4 is offered in `media_ratios` and the
  publisher cuts to it (`PublishImageCropper::targetRatio()` uses the fixed format), as before.

## Models / Tables
- `GridPlan` (`grid_plans`): `org_id`, `client_id`, `social_account_id` (an Instagram account of the
  same client — enforced by `StoreGridPlanRequest`; fixed once created), `campaign_id` nullable,
  `name`, `status` (`GridPlanStatus`), `review_message` (the note for the client),
  `pinned_count` 0–3, `include_live_feed`, `version` (bumped on every change; stale-write guard),
  `checklist_snapshot` json, `approved_at`/`approved_by`/`approved_layout_hash`,
  `order_risk_notified_at`, `created_by`, soft deletes. Index `(client_id, status)`. `booted()`
  busts the portal's cached grid counts on status change/delete.
- `GridTile` (`grid_tiles`): `grid_plan_id`, `position` (1 = top-left = newest; unique per plan,
  rewritten in a transaction by `GridPlanChanges::writePositions()` — move out of the way by +30000,
  then into place, since SQLite can't defer), `kind` (`GridTileKind`), `format` (`MediaRatio`;
  3:4 default, 9:16 for Reels), `cover_asset_id`, `placeholder_colour` (`CampaignColour` token, never
  a hex), `team_note` (staff only), `caption_idea`, `planned_local_date`/`_time` (account
  timezone), `split_group_id` (uuid), `post_id` (unique, `nullOnDelete`; soft-deleting the post also
  frees it via `Post::booted()`).
- `grid_tile_assets` pivot: `sort_order`, `crop_x`/`crop_y`/`crop_zoom`, `alt_text` — same columns
  as `post_assets`, copied with `GridPlanService::pivotRow()`.
- `GridPlanActivityLog` (`grid_plan_activity_logs`), `GridComment` (`grid_comments`, with nullable
  `grid_tile_id` for tile-pinned comments, `internal_only`) + `grid_comment_mentions`.
  `GridComment::authorName()` ("The client" for a review-link comment).
- `review_tokens.grid_plan_id`: a token carries a post **or** a grid plan, never both, never another
  client's (`CreateReviewTokenAction`).
- `assets.source_asset_id`: a split slice points back at its source image.
- `Post::gridTile()` (HasOne), `Client::gridPlans()`, `ReviewToken::gridPlan()`,
  `Asset::sourceAsset()`.

## Layout core (PHP + TS mirror)
- `App\Services\Grids\GridLayoutService::layout(tiles, pinned, outside)` → `GridLayout`: every
  tile's 0-based `slot` (pinned first), `row`, `column`, publish `sequence` (reverse of position;
  1 = goes out first) and each split group's `aligned`/`offset`/`rows`. Outside posts sit in front
  of the first dated tile they're newer than.
- `App\Support\Grids\GridTileFrame::compute()` → the published frame (`CropBox` maths on
  fractions, same as `cropBox()` in `media-format.ts`; `original` = the image's shape clamped into
  the registry feed range) and the centred 3:4 grid window, `croppedOnGrid`, `hiddenShare`.
- TS mirror: `resources/js/lib/grid-layout.ts` (`gridSlots()`, `gridTileFrame()`,
  `rectImageStyle()`, `ordinal()`, `isOutBy()`, `formatGridMoment()`).
- **Shared fixture** `tests/Fixtures/grid-layout.json` (12 layouts, 61 frames) — run by
  `GridLayoutServiceTest` (PHP) and `resources/js/lib/grid-layout.test.ts` (`npm run test`,
  vitest via vite-plus). Regenerate only when the maths change on purpose.
- `GridPlanService`: loads a plan's relations, `publishAt()` (the post's Instagram time once it has
  one, else the planned local time in the account's timezone), `outsidePosts()`, `frame()`,
  `layoutHash()` (SHA-256 of order + kind + format + cover + asset ids + framing; dates/notes don't
  count), `progress()` (derived, never stored), `pivotFraming()`/`pivotRow()`, `formatsFor()`.

## Actions / Services / Contracts
- Checklist: `EvaluateGridChecklistAction` — hard `tiles`, `media` (no placeholders, carousel
  bounds from the registry, Reels need a video), `formats`, `split_alignment`, `account`; soft
  `grid_crop`, `dates` (past, out of order, min gap — `GridSequenceValidator`), `outside_posts`.
  Each item has `tile_ids`. Snapshotted on every save; the planner shows it live.
- Writes (all in `DB::transaction()`, lock the plan row, bump `version`, re-run the checklist, log
  `grid_plan_id`/`org_id`/`user_id` — via `GridPlanChanges::lock()/record()`):
  `CreateGridPlanAction`, `UpdateGridPlanAction`, `AddGridTilesAction` (assets, selected Image
  Studio variants, placeholders; insert at a position and shift), `UpdateGridTileAction`,
  `ReorderGridTilesAction` (full id list + `expected_version`; stale → `StaleGridPlanException`
  409/toast; split tiles must stay together in order), `RemoveGridTileAction`,
  `DuplicateGridPlanAction`, `ArchiveGridPlanAction`, `DeleteGridPlanAction` (soft delete + revoke
  review links), `SplitAssetIntoGridTilesAction` (1×3 / 2×3 / 3×3, slices ≥1080px wide, derived
  assets, source untouched; too-small sources refused with the size needed).
- Reopen on edit: a look-changing edit (media, framing, format, kind, cover, order, pinned count)
  of an `approved`/`changes_requested` plan moves it to `draft` with a "Reopened by edit" log.
  Names, notes, caption ideas, dates and the live-feed toggle never reopen.
- Workflow: `GridPlanStatusTransitionMap`, `TransitionGridPlanStatusAction` (row lock, checklist
  gate for `waiting_client`/`approved`, approval stamp + `approved_layout_hash`, client email,
  notifications after commit, `overrideApproval()`), `CreateGridCommentAction` (tile pin, mentions,
  reviewers never internal), `SendClientGridReviewRequestedEmailAction`
  (`ClientGridReviewRequestedMail`), `DecideGridPlanAction` (client decision + stale-version check +
  flagged tiles → tile comments).
- Production: `CreatePostsFromGridTilesAction` (approved only; idempotent; bad tiles reported),
  `MoveGridPostsAction` (submit / send to client as one batch — `GridPostsReadyMail` — / schedule
  approved, via the shared `App\Actions\Posts\BulkTransitionPostsAction`, extracted from the campaign
  bulk action), `PlanGridScheduleAction` (`preview()` writes nothing; `apply()` dates tiles and each
  post's Instagram target through `UpdatePostAction`, so vendor-held channels are recalled and
  re-sent), `GridSequenceValidator` (`grids.min_gap_minutes`, default 10).
- Keep true: `GridDriftService`, `ResolveGridDriftAction` (`tileFromPost()` reopens; `postFromTile()`
  through `UpdatePostAction`), `AddOutsidePostToGridAction`, `GridOrderRiskService` (checked on
  every grid post status change from `Post::booted()`; notifies the creator once —
  `GridOrderAtRisk`), `HoldGridPostsAction` ("Hold the rest": later scheduled posts → `approved`).
- Feed: `App\Contracts\ProfileFeedSource` → `CompositeProfileFeedSource` (bound in
  `AppServiceProvider`): `MetaGraphProfileFeedSource` (Graph API with the Step 1.9 token, cached
  `grid-feed:{account}` for `grids.feed_cache_minutes`, `VendorHttp::forReads`, https URLs only,
  never logs the token or media URLs) else `PublishedPostsProfileFeedSource`; `refresh()` is
  rate-limited (`grids.feed_refresh_per_hour`).
- AI (Step 18.12): `App\Contracts\GridAiProvider` (DeepSeek / Null, same binding rule as
  `AiProvider`), `GridAiAssist` (`suggestCaptions()`, `checkBalance()` with local dominant colours
  from `ImageNormalizer::dominantColours()`, `fillSlot()` = a 3:4 Image Studio set,
  `useVariant()` marks the variant selected and puts it on the tile). Guardrail-filtered, one
  `ai_usage_logs` row per call, nothing written until "Use". New `ImageAspectRatio::Classic_3_4`.
- Presenting: `GridPlanPresenter` (`plan($plan, forClient)` — the client shape drops team notes,
  post ids, checklist, drift and campaign; `feed()`, `comments()`, `activity()`), `GridLibrary`
  (`summary()` cards, `studioImages()`, `postChip()`).
- Housekeeping: `PruneGridPlansAction` / `grids:prune` (weekly; archived plans untouched for
  `grids.prune_archived_after_months`; unused split slices only).

## Policy
`GridPlanPolicy`: `viewAny`/`view` — staff of the org (Viewer included), a `ClientReviewer` only for
their own client and only `clientVisible()` statuses; `create`/`update`/`transition`/`produce` —
Owner/Strategist/Designer (`update` false while `waiting_client` or `archived`); client reviewers may
only move `waiting_client → approved|changes_requested`; `overrideApproval` — Owner, client-required
plans; `delete` — Owner/Strategist; `useAi` — Owner/Strategist while AI is on.

## Routes / Controllers
- `GET /grids` → `Grids\GridPlanIndexController` — the org-wide grids hub (sidebar: 05 Clients →
  Grids; command palette "Grids"), filter by client/status, "New grid" for any client with an
  Instagram account (`GridPlanPolicy::viewHub`; client contacts → portal). **Step CG-1.2:** now
  `workspace:agency`-guarded (was the last unguarded shared grid surface); a business org is
  redirected to `business.grids.index` (`EnsureWorkspaceType` equivalents).
- **Business shell (Step CG-1.2):** `GET /grid` → `Business\GridController@index` (`business.grids.index`,
  the brand's plans + an `EmptyState` when the brand has no Instagram account) and `POST /grid` →
  `@store` (`business.grids.store`). Brand-implicit — the brand comes from `CurrentWorkspace`;
  `StoreGridPlanRequest` resolves it through `ResolvesWorkspaceBrand` (route `{client}` on the agency
  surface, else the primary brand). The planner (`grids.edit`), tiles, workflow, production and AI are
  the **shared** `grids.*` routes, reused unchanged. `NewGridDialog` gained an optional `storeUrl`
  prop so the business hub posts to the brand-implicit route. Grid-produced posts inherit the primary
  brand's `default_approval_mode = internal_only`, so they skip client review (the review-token link
  stays available as a sponsor "Share preview").
- `GET/POST /clients/{client}/grids` → `Grids\GridPlanController@index|store` (Grid tab — always
  shown to staff; without a live Instagram account it explains why there's no "New grid").
- `GET /grids/{grid}/edit` (planner; client reviewers → portal), `PUT /grids/{grid}`,
  `DELETE /grids/{grid}`, `POST …/duplicate`, `POST …/feed/refresh`.
- `GridTileController`: `POST …/tiles`, `PUT|DELETE …/tiles/{tile}` (scoped), `POST
  …/tiles/reorder`, `POST …/split`.
- `GridWorkflowController`: `POST …/transition`, `…/override-approval`, `…/comments`.
- `GridProductionController`: `POST …/posts`, `…/posts/bulk`, `GET|POST …/schedule`,
  `POST …/schedule-approved`, `…/hold`, `…/outside-posts/{post}`, `…/tiles/{tile}/sync-from-post`,
  `…/tiles/{tile}/restore-post`.
- `GridAiController`: `POST …/ai/captions`, `…/ai/balance` (`throttle:ai`),
  `…/tiles/{tile}/ai/fill` (`throttle:ai-images`), `…/tiles/{tile}/ai/use`.
- Review link: `GET /review/{token}` renders `review/grid` for a grid token;
  `POST /review/{token}/grid` → `ReviewController@decideGrid`.
- Portal: `GET /portal/grids`, `GET /portal/grids/{grid}`, `POST …/decide`, `POST …/comments`
  (`PortalGridController`; `EnsureClientPortalAccess` 404s other clients' and unsent grids).
- Preview mirrors the GETs (and lists a few writes so the 403 can be asserted).

## Frontend
- Pages: `clients/tabs/grids.tsx` ("Plan the grid."), `grids/edit.tsx` (the planner),
  `review/grid.tsx`, `portal/grids/index.tsx`, `portal/grids/show.tsx`.
- Components (`components/grids/`): `grid-preview` (phone frame, IG light/dark, plan-only, crop
  truth, split seams, device sizes, as-of scrubber, lightbox with `SocialPostPreview`),
  `grid-board` (drag and keyboard reorder — Space lift / arrows / Space drop / Escape, live
  announcements, split blocks), `tile-inspector`, `grid-framing-editor` (focus + zoom with the live
  grid window), `add-tiles-dialog`, `grid-workflow`, `grid-production`, `grid-ai-panel`,
  `grid-decision`, `grid-card`, `grid-tile-image`, `types`.
- `PostDiscussion` gained optional `commentForm`, `statusKind`, `renderChip`, `onCommentHover`,
  `composerExtra` and is reused for grid comments/activity. `lib/status.ts` has the `gridPlan`
  kind (post tones; `in_production`, `live` derived). `/grids` belongs to chapter 04.

## Business Rules
- Position 1 is top-left; publish order is the reverse. Split groups fill whole rows once pinned
  posts and outside posts are counted, and move as one block.
- One Instagram account per plan; other channels can be added to a tile's post afterwards.
- Plan status is stored; production progress (in production, live) is derived.
- Client payloads never carry team notes, internal comments, Image Studio data, costs, billing,
  post ids of unsent work or other clients' data.
- Stale writes are refused: reorders (`expected_version`) and client decisions (`version`).
- Grid posts go out in order a minimum gap apart; breaking it warns (grid `dates`, post
  `grid_order`) but never blocks. Drift shows as `grid_match` on the post.
- Nothing is ever scheduled, reordered or held on its own — every move is a person's click.

## Tests
- `tests/Unit/Services/Grids/GridLayoutServiceTest.php` (+ `resources/js/lib/grid-layout.test.ts`).
- `tests/Feature/Grids/`: `GridPlanModelAndPolicyTest`, `GridChecklistTest`,
  `ProfileFeedSourceTest`, `GridPlanActionsTest`, `GridPlannerPageTest`, `GridWorkflowTest`,
  `GridClientReviewTest`, `GridProductionTest`, `GridKeepTrueTest`, `GridAiAssistTest`,
  `GridHousekeepingTest`. Fixtures: `tests/Concerns/BuildsGridPlans.php`.

## Known Gaps / Open Questions
- Pinned posts are a manual count (no Graph API field). The preview takes the first `pinned_count`
  feed items as the pinned ones.
- The as-of scrubber treats undated tiles as not yet out.
- "Fill this slot" uses draft-tier images (no "Make final" step); finalising stays in the Image
  Studio.
- The live feed only reaches 30 items and is cached 15 minutes; "Refresh profile" busts it.
- Browser check (2026-10-20): the planner was checked in light mode at desktop width on
  `/preview/grids/1/edit` (board, inspector, checklist, phone preview). Still to check by eye: dark
  mode, phone width, the review link and the portal grid pages, and drag-reordering with a mouse.

## Changelog
- 2026-10-27: Step CG-1.2 (competitor-gap) — the grid planner switched on for the business shell.
  New `Business\GridController` (`/grid` hub + create) + `business/grids/index.tsx`, reusing the shared
  planner/production/AI routes and `GridLibrary`/`GridPlanService`. `StoreGridPlanRequest` now uses
  `ResolvesWorkspaceBrand`; `NewGridDialog` gained a `storeUrl` prop; the agency `/grids` hub is now
  `workspace:agency`-guarded with an `EnsureWorkspaceType` redirect to the business hub. Grid as a
  Posts sub-item + ⌘K. Tests: `BusinessGridTest` (create, hub, produce+schedule, cross-org refused,
  agency-hub redirect); agency grid suite unchanged (planner/workflow/production/model-policy 45/45).
  Verified: Pint, PHPStan L7, tsc. (Pre-existing, unrelated: the image-split test crashes the PHP
  process on this environment — GD/image slicing, untouched here.)
- 2026-10-24: UI — the grids hub (`pages/grids/index.tsx`) now opens with the Ink `ChapterHero` in place of `PageHeader`, leading with a `HeroStat` of the total grid plans; "New grid" renders as the `spark` CTA (`NewGridDialog` `triggerVariant`/`triggerSize`). See `frontend.md`.
- 2026-10-23: Step M-5.6 — the grid planner is recognised as the MVP's "Instagram grid planner" (it
  supersedes M-5.6's simpler live-feed spec). Added a **"Grids"** entry point on the new content hub
  (`/content` → `grids.index`), so the planner is reachable from the hub as well as the client page,
  campaign workspace and portal. No model/behaviour change. See `mvp.md` › M-5.6.
- 2026-10-20: The client page's Grid box now lives in the "More" menu instead of the visible row (keeps the row to five boxes); same route, same `can.viewGrids` gate.
- 2026-10-20: Discoverability fix — the client's Grid tab now always shows for staff (it used to hide when the client had no live Instagram account, leaving the page reachable only by URL) and says "Add an Instagram account first." with a link to Social accounts; new org-wide grids hub `GET /grids` under 04 Clients in the sidebar and the command palette, with a shared `NewGridDialog`. Fixed: the hub's create check needs the client's `org_id` loaded. Tests: `GridPlannerPageTest`.
- 2026-10-20: Phase 18 built (Steps 18.1–18.14): data model, layout core with the shared PHP/TS
  fixture, live profile feed, plan/tile actions, planner UI, preview, workflow and client
  approval, tiles → posts, sequence scheduling, drift and order-at-risk, AI assist, preview fixture
  and pruning.
