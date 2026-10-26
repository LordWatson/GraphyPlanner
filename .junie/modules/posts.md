# Posts

## Purpose
Core content data model: a `Post` (the piece of content) and its `PostTarget`s (one row per selected
social account, with independent per-target scheduling), the Step 0.9 status-transition state
machine, approval records, activity log, comments (with `internal_only`), the `ReviewToken` model,
the Step 0.10 full editor UI (caption/hashtags/music/location, per-target schedule rows, media,
the §6 pre-schedule checklist, transition controls, comments, activity log), (via a separate
read-only projection) the Step 0.11 org-wide calendar UI — see `.junie/modules/calendar.md` — and
the Step 0.13 unauthenticated client review portal (`/review/:token`), which emails the client (via
Resend) a `ReviewToken`-scoped link whenever a post lands in `waiting_client`.

## Models / Tables
- `Post` (`posts`): `org_id`, `client_id`, `campaign_id` (nullable), `created_by` (nullable),
  `status` (`App\Enums\PostStatus`, default `idea`), `approval_mode` (`App\Enums\ApprovalMode`,
  default `client_required`), `master_caption`, `review_message` (nullable text — a free-form note
  for the client, shown on the Step 0.13 review portal and included in the review-requested email;
  distinct from `master_caption`, which is the actual post copy), `hashtags` (json array), `music`
  (json object), `location` (json object), `media_format` (nullable json — image format per channel,
  `{"instagram": "4:5", ...}`, values from `App\Enums\MediaRatio`; a missing key means the channel's
  default `original`; read via `Post::mediaRatioFor(Platform)`), `checklist_snapshot` (json,
  last-evaluated §6 checklist result), `publish_dispatch_id` (nullable uuid, Step I-1.2 — the one publish job allowed to act on
  a `scheduled` post; see `publishing.md`).
  Relations: `organization`, `client`, `campaign`, `creator`, `targets` (`HasMany<PostTarget>`),
  `assets` (`post_assets` pivot, `withPivot(crop_x, crop_y, crop_zoom)`).
- `post_assets` pivot: `post_id`, `asset_id`, plus each attached image's framing — `crop_x`/`crop_y`
  (decimal 0–1 focus point from the top-left, default 0.5) and `crop_zoom` (decimal 1–3, default 1).
- `PostTarget` (`post_targets`): `post_id`, `social_account_id`, `scheduled_local_date`,
  `scheduled_local_time`, `scheduled_at_utc`, `reminder_sent_at` (nullable timestamp — set once the
  "post scheduled soon" reminder email has been sent for this target, so it's never sent twice; see
  `SendUpcomingPostReminderEmailsAction`), `push_reminder_sent_at` (Step B-7.4 — the same dedup, for
  the business "goes out in ~30 minutes" web-push; see `SendPostPushRemindersAction` in
  `.junie/modules/business-workspaces.md`), `status`/`external_post_id`/`error` (publish outcome),
  `publish_attempt_id` (uuid) and `publish_requested_at` (Step I-1.3 — idempotency key and
  at-most-once stamp per publish attempt). Unique on `(post_id, social_account_id)`. Relations:
  `post`, `socialAccount`. Also reachable via `SocialAccount::postTargets()` and `Client::posts()`.
- `PostActivityLog` (`post_activity_logs`): `post_id`, `user_id` (nullable), `from_status` (nullable,
  `PostStatus`), `to_status` (`PostStatus`), `note` (nullable), `review_token_id` (nullable FK —
  Step I-1.6 replaced the old `review_url` column, which stored the plain review link and undid the
  point of hashing tokens at rest; only the minted token's id is kept, on the `waiting_client`
  hop). One row is created on every successful status transition
  (`TransitionPostStatusAction`). Relations: `post`, `user`. Reachable via `Post::activityLogs()`.
- `PostApproval` (`post_approvals`): `post_id`, `user_id` (nullable), `decision`
  (`App\Enums\ApprovalDecision`: `approved` | `changes_requested`), `comment` (nullable). Created only
  when a transition's destination status is `approved` or `changes_requested`. Relations: `post`,
  `user`. Reachable via `Post::approvals()`.
- `PostComment` (`post_comments`): `post_id`, `user_id` (nullable), `body`, `internal_only` (bool,
  default `false`). Relations: `post`, `user`. Reachable via `Post::comments()`.
- `ReviewToken` (`review_tokens`): `token_hash` (sha256 of the plain token — hashed at rest per §10,
  the plain value is only ever returned once by `CreateReviewTokenAction`), `client_id` (nullable),
  `post_id` (nullable), `expires_at`, `revoked_at` (nullable). `isValid()` checks not revoked and not
  expired. Reachable via `Post::reviewTokens()`. Consumed by `ReviewController` (Step 0.13).
- `post_assets` (pivot, no model): media attached to a post (Step 0.10). Reachable via
  `Post::assets()` (`BelongsToMany<Asset>`) and `Asset::posts()`. A post reuses assets already
  uploaded/linked to its client rather than duplicating storage.

## Actions / Services / Contracts
- `App\Actions\Posts\ListClientPostsAction`: the client page's Posts tab — a client's posts
  newest first, 8/page (`PER_PAGE`), filtered by caption search (case-insensitive, `%`/`_`
  matched literally), `status`, `campaign` (id) and `platform` (any target on that channel).
  Archived posts are hidden from the default list and surface only when `status` is explicitly
  set to `archived`.
- `App\Actions\Posts\EvaluatePostChecklistAction` (Step 0.10): evaluates the §6 pre-schedule
  checklist for a `Post` and returns `{items: [{key, label, passed}], passed}`. Interim item list
  (exact §6 rules weren't available in this repo): `caption` (master caption filled in), `targets`
  (at least one target), `schedule` (every target has a local date/time), `media` (at least one
  asset attached, **unless** every target's platform is text-only-capable — `Platform::isTextOnlyCapable()`,
  currently only `LinkedIn` — this implements the Phase 0 exit criterion "Schedule blocked without
  media unless platform is text-only"). Called on every status transition and every `UpdatePostAction`
  call to keep `Post::checklist_snapshot` fresh, and re-run (not just read from the snapshot) inside
  `TransitionPostRequest` before any move to `scheduled` so a stale snapshot can never let an
  incomplete post through.
- `App\Actions\Posts\UpdatePostAction` (Step 0.10): updates a post's content fields
  (`master_caption`/`review_message`/`hashtags`/`music`/`location`/`campaign_id`/`approval_mode`); when `targets`/
  `asset_ids` keys are present in the input they **fully replace** the post's `PostTarget`s /
  attached assets (delete-and-recreate for targets, `sync()` for assets) — each target's
  `scheduled_at_utc` is recomputed independently from its own social account's timezone, same as
  `CreatePostAction` (spec §4.6). Always refreshes `checklist_snapshot` at the end. Wrapped in
  `DB::transaction`; logs `Post updated`.
- `App\Actions\Posts\CreatePostAction`: creates a `Post` for a client and, for each entry in
  `targets[]`, creates a `PostTarget` whose `scheduled_at_utc` is computed **independently** from
  that target's own `SocialAccount::timezone` (via `Carbon::parse($date.' '.$time, $account->timezone)->utc()`).
  Two targets on accounts in different timezones therefore never share a UTC instant unless their
  local date/time happen to coincide once converted — this is the critical invariant from spec §4.6.
  Wrapped in `DB::transaction`; logs `Post created` with `client_id`/`post_id`/`target_count`.
- `App\Actions\Posts\PostStatusTransitionMap`: the explicit `Post::status` state machine as a single
  `public const array MAP` (`from status value => list of allowed to status values`), plus
  `isAllowed(PostStatus $from, PostStatus $to): bool` and `allowedFrom(PostStatus $from): array`. Kept
  as one first-class map (per plan Step 0.9) rather than scattered if/else, so every legal transition
  is visible at a glance. See the map itself for the exact edges; broadly: `idea → draft`,
  `draft → internal_review`, `internal_review → {draft, waiting_client, approved}`,
  `waiting_client → {approved, changes_requested}`, `changes_requested → {internal_review, draft}`,
  `approved → {scheduled, draft}`, `scheduled → {publishing, draft}`, `publishing → {published, failed}`,
  `failed → {draft, scheduled}`, `archived → draft`, and every non-terminal status can go to
  `archived`.
- `App\Actions\Posts\TransitionPostStatusAction`: moves a `Post` to a new status. Throws
  `InvalidArgumentException` if the move isn't in `PostStatusTransitionMap` (defense in depth — the
  HTTP layer already rejects illegal transitions via a validation rule). Always creates a
  `PostActivityLog` row; additionally creates a `PostApproval` row when the destination is `approved`
  or `changes_requested`. Wrapped in `DB::transaction`; logs `Post status transitioned`. Does **not**
  perform authorization itself — callers must check `PostPolicy::transition` first.
- `App\Actions\Posts\CreatePostCommentAction`: creates a `PostComment`. Forces `internal_only = false`
  whenever the acting user's role is `Role::ClientReviewer`, regardless of what was submitted, so
  internal notes can never leak to a client. Logs `Post comment created`.
- `App\Actions\ReviewTokens\CreateReviewTokenAction`: creates a `ReviewToken` scoped to a `Client`
  (and optionally one `Post`), hashing the generated token with `sha256` before persisting and
  returning `{token: <plain>, model: ReviewToken}` — the plain value is never stored, only returned
  from this call. Defaults `expires_at` to 7 days out. Logs `Review token created`. Invoked by
  `SendClientReviewRequestedEmailAction` (Step 0.13) every time a post enters `waiting_client`.
- `App\Actions\Posts\SendClientReviewRequestedEmailAction` (Step 0.13): issues a fresh `ReviewToken`
  scoped to the post's client/post via `CreateReviewTokenAction`, builds the `/review/{token}` link,
  and sends `App\Mail\ClientReviewRequestedMail` (via Resend, `resources/views/emails/client-review-
  requested.blade.php`) to `Client::approval_email`. If the client has no `approval_email` on file,
  the send is skipped (with a warning log) and `null` is returned rather than failing the transition.
  Invoked from `TransitionPostStatusAction` whenever the destination status is `waiting_client`; the
  returned URL (or `null`) is stored on the resulting `PostActivityLog::review_url`.
- `App\Actions\Posts\ResendClientReviewEmailAction`: re-sends `ClientReviewRequestedMail` to
  `Client::approval_email`, reusing the signed URL already stored on a given `PostActivityLog::
  review_url` (no new `ReviewToken` is issued) — used when an org user needs to help a client who
  lost/never received the original email. Throws `InvalidArgumentException` if the log has no
  `review_url`; skips (with a warning log) if the client has no `approval_email` on file.
- `App\Actions\Posts\SendUpcomingPostReminderEmailsAction`: finds every `PostStatus::Scheduled`
  post's `PostTarget` whose `scheduled_at_utc` falls within the next `$days` days (default 3) and
  whose `reminder_sent_at` is still null, groups them by organization, and emails every user of that
  organization one `App\Mail\UpcomingPostReminderMail` digest (`resources/views/emails/upcoming-
  post-reminder.blade.php`) listing all due occurrences. Marks each notified target's
  `reminder_sent_at` (wrapped in `DB::transaction` per organization, alongside the sends) so a re-run
  never double-emails the same target. Logs `Upcoming post reminder emails sent` with `org_id`/
  `target_count`/`user_count`. Invoked by the `posts:send-upcoming-reminders` artisan command.
- `App\Actions\Posts\ReschedulePostAction` — the calendar's drag-and-drop move. Shifts every dated
  `PostTarget` of a post by `$days` whole days *in place* (ids/status kept): only
  `scheduled_local_date` moves, `scheduled_local_time` is untouched, and `scheduled_at_utc` is
  re-derived per target from its own account timezone (so a move across a DST change keeps the local
  wall-clock time). Clears `reminder_sent_at` so the upcoming-post reminder fires for the new date.
  Throws a `ValidationException` on `days` if any target would land in the past (the exact instant
  when a time is set, otherwise the local date vs today). Writes wrapped in `DB::transaction`; logs
  `Post rescheduled from calendar` with `post_id`/`org_id`/`user_id`/`days`/`target_count`.
- `App\Actions\Posts\RescheduleTargetAction` (Step M-5.7 / I-5.1) — the calendar's single-channel
  move (week/day time grid). Moves **one** `PostTarget` to a new `scheduled_local_date` and, when the
  time grid supplies it, a new `scheduled_local_time` (otherwise the target keeps its own time);
  `scheduled_at_utc` is re-derived from that account's timezone and `reminder_sent_at` cleared. The
  post's siblings are untouched, so independently-scheduled local times are never collapsed. Same
  vendor-recall (`VendorScheduleService`) + scheduled-re-dispatch (`ReschedulePostAction::
  redispatchIfScheduled`) lifecycle as the whole-post move. Rejects a past landing as a
  `ValidationException` on `scheduled_local_date`. Wrapped in `DB::transaction`; logs
  `Post target rescheduled from calendar` with `post_id`/`target_id`/`org_id`/`user_id`/`has_time`.

## Console Commands
- `posts:send-upcoming-reminders {--days=3}` (`App\Console\Commands\SendUpcomingPostRemindersCommand`):
  runs `SendUpcomingPostReminderEmailsAction`, printing how many `PostTarget`s were reminded about.
  Scheduled daily at 08:00 in `routes/console.php` (`Schedule::command(...)->dailyAt('08:00')`).

## Phase M-2 additions

- **Columns**: `posts.link_url`, `posts.first_comment` (masters), `posts.cover_asset_id`
  (`coverAsset()`), `posts.thumb_offset_ms`; `post_targets.caption_override`, `hashtags_override`
  (json), `first_comment`, `link_url`, `format` (null = Auto); `post_assets.alt_text`,
  `post_assets.sort_order` (`Post::assets()` orders by it). Override semantics: `null` = use the
  master, `''` / `[]` = none on this channel (the form sends `UpdatePostRequest::EMPTY_OVERRIDE`).
- **PostTarget**: `effectiveCaption()`, `effectiveHashtags()`, `effectiveFirstComment()`,
  `effectiveLink()`, `publishedCaption()`, `isCustomized()`, `resolvedFormat()`, `isVendorHeld()`.
  **Post**: `publishableMedia()` (a video post sends one video, else the images), `mediaKinds()`,
  `canBeRecalled()`.
- **UpdatePostAction** upserts targets per account (keeps id, status, vendor ids and overrides;
  removes unlisted ones; a changed time re-arms the reminder), saves media order/alt text/framing,
  and recalls + re-submits vendor-held channels (see `publishing.md`).
- **EvaluatePostChecklistAction** now checks every channel against the registry with effective
  values: `caption` (each channel has one), `caption_length`, `format` (+ `fixes` to a format that
  fits), `media_fit` (size, video length, video shape — needs `ProbeAssetJob` data, skipped when
  unknown), `account_settings` (registry required settings), and soft `hashtags`, `links`,
  `first_comment`, `media_framing` (+ a one-click crop fix for full-screen photo formats). Each
  failing item carries `detail` (one line per channel).
- **Approval mode (M-2.9)**: `PostStatusTransitionMap::isAllowedFor()/needsClientApproval()/
  allowedFor()` — a `client_required` post can't go `internal_review → approved`;
  `TransitionPostStatusAction::overrideApproval()` (Owner, `PostPolicy::overrideApproval`, reason
  required, recorded as "Approval override: …" on the activity log and approval). New posts take
  `clients.default_approval_mode`. The factory default is `internal_only` so older tests keep their
  meaning; tests that need the client step set `client_required`.
- **Recall moves (M-2.5)**: `publishing → approved/draft` when `canBeRecalled()`; `PostPolicy`
  `update`/`reschedule`/`transition` allow it only then.
- **Editor**: per-channel content is edited in the composer's channel scope (`composer.tsx`:
  format, caption, hashtags, first comment, link per channel with live counts), master Link + First comment, media alt text +
  video cover/frame (`media-details.tsx` — the alt-text list is a `Collapsible`, collapsed by
  default and auto-opened only when some alt text is already filled, since it's rarely used),
  approval mode select, checklist details and one-click
  fixes, per-channel previews (Story/Reel/Short/X/Threads/Pinterest/Google layouts), Owner
  "Approve without client" dialog. Review link and portal show each channel's published caption
  and first comment (`PostMediaPresenter::channelPreview()`), never raw overrides.
- **Editor layout (Step 24.10 — guided stepper).** `pages/posts/edit.tsx` composes the editor as a
  five-step guided flow instead of stacking every control at once:
  - A progress spine (`editor/step-spine.tsx` → `StepSpine`) over the steps **Write · Where & when ·
    Media · Settings · Review & send**; each step's disc shows its live readiness
    (`editor/editor-steps.ts`: `tabStatus` for the four build steps, `reviewStatus` for Review, plus
    a server-error overlay). Steps are free to click — navigation never blocks.
  - One build step shows at a time (`editor/editor-stepper.tsx` → `StepPanel`, all panels stay
    mounted so every field still submits; `ErrorStepSwitcher` jumps to the first step with a server
    error) beside a **constant live preview** in the right rail (`editor/editor-sidebar.tsx`), which
    also carries a compact readiness nudge (hidden on the Review step) into Review & send.
  - A single action bar (`editor/step-action-bar.tsx` → `StepActionBar`) carries the save state,
    Back / Next (Next advances through the steps; the Review step has no Next), and Save.
  - **All lifecycle actions now live only in the final Review & send step** (`editor/review-step.tsx`
    → `ReviewStep`): read top-down as "a last look, then move it along" — the full
    `ReadinessChecklist`, the business quick-schedule bar (`business-schedule-bar.tsx`),
    `post-workflow.tsx` (stage track, the one recommended move, and every other legal move), the
    client feedback board (`FeedbackSection`), then the `PostDiscussion` (comments + activity). They
    are no longer stacked above the work.
  - **Step 24.11 — nothing orphaned at the bottom.** The three modules that used to sit full-width
    below the two-column grid (with a big empty gap before them) now live in the steps they belong
    to: the AI image studio (`ImageStudioSection`, "Make it visual") is appended to the **Media**
    step; the feedback board (`FeedbackSection`) and the discussion (`PostDiscussion`) moved into
    **Review & send** (see above). Each therefore sits in the left column beside the preview rail at a
    readable width, never full-width. When there's feedback to read (`changes_requested`, `failed`,
    `partially_published`), the editor opens straight on Review & send (`useEditorNavigation` takes an
    `initialTab`) with the discussion on its activity tab.
  - **Open step survives a save.** A successful save remounts the page (Inertia drops the component
    state when there are no validation errors), which would otherwise snap back to Write.
    `useEditorNavigation` takes a `persistKey` (`post-editor-step:{post.id}` from `edit.tsx`) and
    mirrors the active step to `sessionStorage`, reading it back as the initial step on remount, so
    the user stays on the step they were working on (e.g. Media). `initialTab`/`needsFeedback` is the
    fallback when nothing is stored (first load).
  - **Media reordering (Media step).** `media-picker.tsx` lets attached tiles be reordered the way
    `grid-board.tsx` does: drag a tile onto another (mouse) or focus a tile's grip handle and press
    space to lift, the arrow keys to move, space to drop (escape cancels), announced via an
    `aria-live` region. A keyboard drag stages its order (`lifted`) until dropped; the numbered badge
    is the live publish order; `onChange` commits the new order into editor state (`asset_ids` hidden
    inputs), saved like any other edit. Thumbnails are `draggable={false}` so the tile owns the drag.
  - Read-only viewers skip the stepper entirely (`editor/read-only-editor.tsx` → `ReadOnlyEditor`,
    extracted from `edit.tsx` to keep it under the 300-line editor budget): `ReadOnlyContent` +
    feedback + discussion + image studio in the left column beside the preview, with
    `BusinessScheduleBar`/`PostWorkflow` shown above it (so "move it back from the workflow above"
    still reads true for a locked post). The pure step helpers are unit-tested in
    `editor/editor-stepper.test.ts`.

## Phase M-3 additions (Step M-3.2)
- `PostController@edit` adds `best_time` to each `targetAccounts` entry (`BestTimeService`). A
  channel row in `ChannelSchedule` shows "Best times" chips (or "Best times appear after N measured
  posts"); a chip sets that row to the next such slot on the account's clock (`nextWeeklySlot()`).
- A post status change queues `RecomputeClientHealthJob` (client health v2, `clients.md`).

## Phase M-6 additions (Step M-6.4 — visual feedback)

`post_comments` gained `anchor_type` (`CommentAnchorType`: image/video/caption), `anchor` (JSON:
image `{asset_id,x,y}`, video `{asset_id,t}`, caption `{channel,start,end,quote}`), `suggested_edit`
+ `suggestion_resolved_at`, `parent_id` (thread replies), and `resolved_at` / `resolved_by_user_id`
(migration `2026_11_06_100100`). `PostComment::isFeedback()` = a root comment that is pinned or
carries a suggestion; `Post::hasUnresolvedFeedback()` (root, unresolved, pinned-or-suggestion) gates
approval — `TransitionPostStatusAction` refuses `→ approved` with open feedback unless an Owner
overrides. `CreatePostCommentAction` takes `anchorType`/`anchor`/`parentId`/`suggestedEdit`;
`ResolvePostCommentAction` and `AcceptSuggestedEditAction` (applies a suggested caption to
`master_caption` via `UpdatePostAction`, then resolves) live in `App\Actions\Posts\Feedback`.
`PostFeedbackPresenter::threads($post, $viewer, $forClient)` shapes threads for the three surfaces
(internal threads hidden from clients). Staff routes `comments.resolve` / `comments.accept-suggestion`
/ `comments.dismiss-suggestion` (`PostFeedbackController`); the portal has `portal.comments.resolve`.
Frontend: the shared `components/feedback/*` board (pin overlay with click-to-place, caption
select-to-comment, video timestamps, threads, resolve, suggested-edit accept) is used in the editor
(staff), the portal (reviewers) and the review link (read-only render). Tests:
`tests/Feature/Feedback/VisualFeedbackTest`.

## Phase M-6 additions (Step M-6.3 — multi-approver approvals)

`Post::approvalSteps()` → `approval_steps`, built when a post enters `waiting_client`
(`BuildApprovalStepsAction`, hooked in `TransitionPostStatusAction`). A client decision goes through
`App\Actions\Posts\Approvals\SubmitClientDecisionAction` (not `TransitionPostStatusAction` directly)
so the client's `approval_policy` (any / all / sequential) decides when the post is released to
`approved`. Staff can download the approval certificate for an approved post
(`posts.approval-certificate` → `GenerateApprovalCertificatePdfAction`). Full detail (steps, policy,
certificate, approve-all) lives in `client-portal.md` › Phase M-6 (M-6.3). `PostRevision::contentHash()`
is the SHA-256 printed on the certificate.

## Phase M-5 additions (Step M-5.1 — content hub)

The agency content hub (`/content`): every client's posts in one place, as a filterable table or a
status board, with a bulk-action bar, CSV export and personal saved filter views. The agency
equivalent of the business shell's `/posts`; agency-only (`workspace:agency` redirects a business org
to `business.posts.index`) and behind `portal.redirect` (a client reviewer never sees it). The board
and the bulk move reuse the campaign machinery so the rules can't drift.

- **`App\Actions\Posts\ListPostsAction`** — the org-wide query (the `OrganizationScope` pins the
  tenant). `query(array $filters): Builder` is shared by the paginated table (`__invoke`, 20/page,
  newest-updated first) and the capped board; filters: caption search (literal, `%`/`_` escaped),
  client, campaign, status, platform, scheduled-date range and a has-media toggle. Archived posts are
  hidden unless the status filter is `archived`.
- **`App\Actions\Posts\GetContentBoardAction`** — the status board from the filtered query, grouped by
  `ContentHubPresenter::COLUMNS`. Capped at `CAP = 400`; `capped` tells the UI to say "narrow the
  filters". Each card carries its legal `drop_targets` for the viewer.
- **`App\Actions\Posts\BulkUpdatePostsAction`** — bulk `move` (status), `archive`, `campaign`
  (set/clear, pinned to the post's own client) and `reschedule` (shift whole days). Moves/archive run
  through `BulkTransitionPostsAction` (state machine + `PostPolicy::transition`), reschedule through
  `ReschedulePostAction` (vendor recall + re-dispatch). Returns `{done, skipped[]}` with a plain
  reason per skip (the I-4.1 pattern); logs a summary. CSV export is a stream in the controller, not
  here.
- **`App\Support\Posts\ContentHubPresenter`** — `row()` (table) and `boardCard()` (board) share a
  core (status, caption, client, campaign chip, thumbnail, channels, schedule); the board card adds
  `drop_targets`. `COLUMNS`/`DROPPABLE` mirror `GetCampaignBoardAction`.
- **`App\Models\SavedPostView`** (`saved_post_views`: `org_id`, `user_id`, `name`, `filters` json,
  unique `user_id+name`) — a staff member's named filter query. Personal, never shared;
  `SavedPostViewPolicy` pins create/view/delete to the owning user and org.
- **`PostPolicy::viewHub(User)`** — the hub gate; staff only (`ClientReviewer` excluded), like
  `viewHome`.
- **Controllers / routes:** `ContentHubController` (`content.index`, `content.csv`, `content.bulk`) and
  `SavedPostViewController` (`content.views.store`, `content.views.destroy`), all thin. The content
  hub is a section of **05 Clients** (sidebar "Posts" subitem; `/content` added to the chapter
  prefixes). `EnsureWorkspaceType` maps `content.index → business.posts.index`.
- **Frontend:** `resources/js/pages/content/index.tsx` — `Page`/`PageHeader`, a table/board view
  switch, a filter toolbar (search, client, campaign, status, platform, date range, media, saved
  views), the shared `DataTable` (bulk bar) and `KanbanBoard` (reused from the campaign board). Bulk
  calls `content.bulk` via `http.post` then reloads `posts`/`board`.
- **Not yet:** an assignee filter/column waits on `posts.assignee_id` (Step M-5.4); an "AI-generated"
  filter has no post-level flag yet. Both noted for the later M-5 steps.

### Step M-5.2 — faster creation & duplicate

- **Create opens the editor** (already true): `PostController@store` and `Business\PostController@store`
  redirect to `posts.edit`, so every entry point lands straight in the editor with its prefill.
- **Entry points:** the client page's `NewPostDialog` (existing), the content hub's **"New post for…"**
  client picker (M-5.1, gated on `can.create`), an agency **command-palette "New post"** (producing
  roles → the hub picker), an AI idea (existing), and `campaign_id`/target-date prefill carried through
  `clients.posts.store`. The calendar empty-slot create is owned by Step M-5.7 (I-5.1).
- **`App\Actions\Posts\DuplicatePostAction`** — copies a post's content (caption, hashtags, music,
  location, link, first comment, media format, cover, `thumb_offset_ms`), its media (the `post_assets`
  pivot: crop + alt text + order, re-attached, not re-uploaded) and its target accounts with their
  per-channel overrides (`caption_override`, `hashtags_override`, `first_comment`, `link_url`,
  `format`). It **never** copies the schedule (`scheduled_*`), the publish state (`status`,
  `external_post_id`, `error`) or the history: the copy is a fresh `draft` for the same client,
  `created_by` the actor, in one `DB::transaction()`. `PostController@duplicate`
  (`POST posts/{post}/duplicate` → `posts.duplicate`) authorizes `view` on the source + `create` on the
  client (so a locked, published post can still be recycled), then opens the copy in the editor.
  Reachable from the editor header ("Duplicate", gated on `page.can.duplicate` via the editor context)
  and the content hub's row menu (`can.duplicate` per row). Tests in `tests/Feature/Posts/DuplicatePostTest.php`.

### Step M-5.3 — autosave, version history & "what changed"

- **`post_revisions`** (`org_id`, `post_id`, `created_by`, `snapshot` json, `shared_at`) — a content
  history. The snapshot is **content only** (caption, hashtags, music, location, link, first comment,
  media format, per-channel overrides, attached media + alt text + order) — never the schedule or
  publish state. `Post::revisions()`. Model `PostRevision`.
- **`App\Services\Posts\PostRevisionService`** — `snapshot()`, `record()` (writes one per save, a
  no-op when content is unchanged so autosave doesn't spam; a `shared` checkpoint marks the version
  shown to the client, reusing the latest when identical), `diff()` (a plain "what changed" payload)
  and `changesSinceLastReview()` (the diff between the two most recent **shared** revisions).
- **Recording hooks:** `UpdatePostAction` now takes `?User $actor` and records a revision at the end
  of each save; `TransitionPostStatusAction` records a **shared** revision when a post enters
  `waiting_client`.
- **Restore:** `App\Actions\Posts\RestorePostRevisionAction` brings a revision's content, media and
  still-present channels' overrides back through `UpdatePostAction` — keeping the current schedule and
  channel set, filtering to assets that still exist. Routes `GET posts/{post}/revisions` (JSON, the
  drawer) and `POST posts/{post}/revisions/{revision}/restore` (`PostRevisionController`; restore
  authorizes `update`, so a locked post can't be restored over).
- **Autosave:** `components/post-editor/editor/use-autosave.ts` (`useAutosave` + `postAutosaves`) —
  draft-like posts (`idea`/`draft`/`changes_requested`, editable) auto-submit the editor form 5 s
  after the last change; the action bar (`editor/step-action-bar.tsx` → `StepActionBar`, which Step
  24.10 merged the old `SaveBar` into) shows "Autosaving…" / "Saved · HH:MM" (`posts.edit` carries
  `updated_at`). The AI image studio block was extracted to
  `components/post-editor/editor/image-studio-section.tsx` to keep `edit.tsx` under the editor line
  budget.
- **History drawer:** `components/post-editor/post-history-drawer.tsx` (a Sheet in the editor header)
  lists versions with `summarizeDiff` (`resources/js/lib/post-diff.ts`) and restores one.
- **"What changed since your last review":** the portal post page and the review link carry
  `changes_since_review` (from `changesSinceLastReview()`, only while `waiting_client`, never on a
  share-preview link), rendered by `components/posts/review-changes.tsx`.
- **C-5.2 staleness:** `BatchReviewCampaignPostsAction` now judges a client decision stale by the
  **content** the client saw — a post with history is stale only if a revision was created after the
  client's `seen_at` (so a schedule tweak or autosave no longer forces a re-review); posts with no
  history fall back to `updated_at`.
- Tests: `tests/Feature/Posts/PostRevisionTest.php`; the C-5.2 change keeps
  `tests/Feature/Campaigns/CampaignCollaborationTest.php` green.

### Step M-5.4 — assignees, due dates & "My work"

- **`posts.assignee_id`, `posts.reviewer_id`, `posts.due_at`** (+ `Post::assignee()`/`reviewer()`,
  fillable, `due_at` cast). `StorePostRequest`/`UpdatePostRequest` accept them (assignee/reviewer must
  be internal staff of the org — a shared `staffRule()`). The editor's Settings tab gains an
  "Assignment" panel (assignee/reviewer selects + due date), wired through the editor state
  (`assigneeId`/`reviewerId`/`dueDate`) and `EditorFormFields`; `posts.edit` passes a `staff` list.
- **Targeted notifications:** `PostNotificationService::internalReviewRecipients()` now pings just the
  reviewer and/or assignee when set (agency), falling back to every internal user when a post is
  unassigned — so assignment stops the whole-org ping.
- **"My work"** (`/my-work`, `MyWorkController` + `GetMyWorkAction`, staff-only via `portal.redirect`
  + `PostPolicy::viewHub`): a person's own posts across clients in four buckets — **due** (assigned,
  due within 3 days or overdue), **review** (reviewer, in internal review), **assigned** (the rest),
  and **mentioned** (tagged in a comment). Reachable from the sidebar ("My work") and the command
  palette. Page `resources/js/pages/my-work/index.tsx`.
- **Content hub:** gains an assignee filter (incl. "Unassigned" = `0`), an Assignee·due column, and a
  bulk **Assign** action (`BulkUpdatePostsAction::ASSIGN`) — the assignee bits deferred from M-5.1.
  `ContentHubPresenter::row` now carries `assignee`/`due_at`; `filterOptions.staff` added.
- **Deliverable handoff:** `StartPostFromDeliverableAction` sets the new post's `assignee_id` from the
  deliverable and `due_at` to its planned date less the client's `approval_lead_days` (C-4.4 → M-5.4).

### Step M-5.8 — bulk import, recycling & recurring posts

- **Columns:** `posts.is_recyclable` (bool), `posts.recycle_after_days` (nullable int),
  `posts.recycled_from_post_id` (self-FK, `recycledFrom()`/`recycledInto()`), `posts.recurrence`
  (`PostRecurrence` enum: `weekly`/`monthly`, null = one-off). All fillable; the editor's new "06
  Repeat & reuse" panel (`repeat-reuse-panel.tsx`) sets them through `UpdatePostRequest`.
- **Recycling (Phase 11):** `RecyclePostAction` copies a post's content, media and target accounts
  (with their per-channel overrides, I-4.7) into a fresh `idea` for the same client — **not** the
  schedule (the user re-picks dates) — and records `recycled_from_post_id`. Rejected unless the
  source is `is_recyclable`. `POST posts/{post}/recycle` → `posts.recycle`
  (`PostPolicy::recycle`: published + reusable + producing role). Home gains a "Ready to recycle"
  bucket (`GetNeedsAttentionItemsAction::recyclingSuggestions` — reusable, published, past its
  window, not yet recycled; window applied in PHP for MySQL/SQLite portability). See `home.md`.
- **Recurring posts:** the same `RecyclePostAction`, given the source's `PostRecurrence`, instead
  drops the next occurrence as a **draft** with each target's date advanced one interval
  (month-end-safe, UTC re-derived per account timezone). `GenerateRecurringPostDraftsAction` +
  `posts:generate-recurring-drafts` (scheduled daily 06:45, in `SchedulerHeartbeat::COMMANDS`) sweeps
  recurring **leaves** that have published and not yet spawned a child — idempotent.
- **Bulk CSV import (Phase 10):** `BulkPostImportController` (`clients/{client}/posts/bulk-import`:
  `index` page, `template` download, `preview` JSON, `bulk-store`). `ParseBulkPostImportAction` turns
  a client-scoped CSV (columns `caption, hashtags, accounts, date, time, format, first_comment, link,
  campaign` — only `accounts` required; handles `;`-separated and resolved against the client;
  campaign by code) into `BulkPostRow`s + per-row errors (`BulkImportParseResult`), never throwing.
  `CreatePostsInBulkAction` creates the whole batch in one `DB::transaction` via `CreatePostAction`
  (each post a `draft`, per-target `scheduled_at_utc` computed independently; `format` applied to the
  targets after). **All-or-nothing:** the controller refuses to import if any row is flagged. Entry
  point: an "Import CSV" button on the client Posts tab. Page `resources/js/pages/posts/bulk-import.tsx`.
- Tests: `tests/Feature/Posts/PostAssignmentTest.php`, `tests/Feature/Posts/MyWorkTest.php`.
  **Not yet:** a dedicated "My work" default section on Home for Designers — the sidebar/palette and
  the page cover access; a Home panel can follow.

## Phase B-3 additions (per-org-type workflow, quick schedule, share preview)
- **`App\Contracts\PostWorkflow`** (Step B-3.1) — the post lifecycle for one workspace type:
  `allowedTransitions(Post, ?User): list<PostStatus>`, `defaultApprovalMode(): ApprovalMode`,
  `requiresReview(Organization): bool`. Implementations `App\Services\Posts\AgencyPostWorkflow`
  (wraps `PostStatusTransitionMap::allowedFor()` unchanged — agency is byte-for-byte as before,
  default mode `client_required`, always `requiresReview`) and `App\Services\Posts\BusinessPostWorkflow`
  (drops the client stages `waiting_client`/`changes_requested`; default mode `internal_only`;
  `requiresReview` reads `organizations.require_post_review`; **review off** allows `draft → approved`
  self-approval, **review on** `draft → internal_review → approved` with `internal_review → draft`
  for "changes needed"; `internal_review → approved` always stays legal so flipping review off never
  strands an in-flight post). `App\Services\Posts\PostWorkflowResolver` (singleton) picks by org
  type. `TransitionPostStatusAction::allowed()`, `PostController@edit`'s `allowedTransitions` and
  `TransitionPostRequest`'s rule all ask the resolver instead of reading the map directly.
  `PostStatusTransitionMap` remains the single source of truth for which raw edges exist; a workflow
  only narrows it. `CreatePrimaryBrandAction` sets the primary brand's `default_approval_mode` to
  `internal_only` so business posts never carry `client_required`.
- **`App\Actions\Posts\QuickSchedulePostAction`** (Step B-3.2) — the one-tap schedule from a
  `draft`/`idea`. Review off: validates the §6 checklist, then walks `→ approved → scheduled` (self-
  approval logged "Marked ready") in **one** transaction reusing `TransitionPostStatusAction` per
  hop, so every side effect (publish job, notifications) defers to the single commit (I-1.4); a
  checklist failure throws `ValidationException` with no state change. `when: 'now'` retimes every
  target to now first (immediate publish). Review on: moves to `internal_review` and notifies
  reviewers. Route `POST posts/{post}/quick-schedule` → `posts.quick-schedule`
  (`QuickSchedulePostRequest`, authorizes `PostPolicy::transition` to `scheduled`). Editor:
  `resources/js/components/post-editor/business-schedule-bar.tsx` renders the business primary
  button(s) ("Post now"/"Schedule", or "Send for review") above the `PostWorkflow` panel, from the
  new `workflow` prop (`is_business`/`requires_review`/`has_times`) and `can.quick_schedule`.
- **Internal-review notifications** (Step B-3.3) — `PostNotificationService::internalReviewRecipients()`
  returns Owners + Managers (`Role::Strategist`) only for a business org (agency unchanged = all
  internal users). `TransitionPostStatusAction` uses it for the `internal_review` audience.
- **Share preview** (Step B-3.4) — `review_tokens.allow_comments`/`allow_approval` (default false)
  widen a read-only partner/sponsor link minted by `App\Actions\Posts\CreateSharePreviewAction`
  (via `CreateReviewTokenAction`). `ReviewToken::isSharePreview()` is true when either flag is set;
  `ReviewController` additionally treats **any token on a business post** as a preview (a business
  post has no `waiting_client` stage). A preview reads with brand-not-client copy (`mode: 'preview'`,
  `post.review_message` hidden), `can_decide`/`can_comment` from the flags. Feedback goes to
  `POST review/{token}/preview-feedback` → `review.preview-feedback` (`PreviewFeedbackRequest` →
  `RecordPreviewFeedbackAction`), which records a `PostComment` (null user) and/or a `PostApproval`
  (null user) but **never transitions** the post; `review.decide` 404s for a preview/business token.
  `PostPolicy::sharePreview` = Owner/Manager. Mint endpoint `POST posts/{post}/share-preview` →
  `posts.share-preview` returns the plain URL once as JSON (`can.share_preview`); editor UI
  `resources/js/components/post-editor/share-preview-dialog.tsx`.

## Phase M-1 additions (Step M-1.6 / I-4.6 — editor decomposition)
- `pages/posts/edit.tsx` is composition only (~200 lines). Under `components/post-editor/editor/`:
  `usePostEditor` (one `EditorState` object instead of 17 `useState`s; actions; Undo toasts when
  a channel or media is removed or changes are discarded), `editor-state.ts` (initial state,
  `normalizeEditorState`, dirty check), `editor-derived.ts` (pure view derivations, unit-tested),
  `PostEditorProvider` context, and one component per panel: `ComposeSection`,
  `ChannelsSection`, `MediaSection`, `SettingsSection`, `EditorSidebar`, `SaveBar`,
  `EditorFormFields` (every hidden field in one place), `EditorHeader`, `ReadOnlyContent`,
  `ShortcutsDialog`; hooks `useAiCaptions`, `useEditorNavigation`.
- The existing editor components were split too (composer → `composer/`, workflow → `workflow/`,
  crop editor → `crop/`, asset library → `asset-library/`, discussion → `discussion/`, live
  checklist logic → `lib/live-checklist.ts`). `scripts/frontend-guards.mjs` fails `npm run check`
  if any editor file passes 300 lines.
- **Tabbed workspace** (`editor-tabs.tsx`): the Write / Where & when / Media / Settings panels are
  a segmented tab bar — one panel shows at a time while all four stay mounted (`TabPanel` hides the
  inactive ones with `display:none`, so every field still submits in the form). `useEditorNavigation`
  holds the shared `activeTab`; the readiness checklist's "jump to the field that fixes this" switches
  to that panel's tab (`jumpTo`), and media-framing jumps to the preview in the right rail instead.
  Each tab shows its readiness at a glance via `tabStatus` (grouped from the live checklist by
  `checkSection`: blocking check open → "to do", soft only → "worth a look", all pass → "ready"). A
  failed save routes to the first panel with a validation error (`ErrorTabSwitcher` / `tabForErrorKey`)
  and marks it, so a hidden tab can't swallow an error. `TabFooter` gives non-blocking Back/Next.
  Settings is no longer a collapsible — the tab already shows/hides it. Covered by
  `editor-tabs.test.ts`.
- **Status moves**: `post-workflow.tsx` lays moves out directionally so moving back and forth is
  obvious — `back`-intent moves are visible outline buttons with a left arrow on the left, forward
  moves on the right (the recommended next step, `primaryTransitionFor`, filled with a right arrow;
  other forwards and the Step M-2.9 "Approve without client" override beside it). Only the rarer
  `system`/`archive` moves and delete live in the `MoveMenu` dropdown (`workflow/transition-controls.tsx`,
  split out to keep `post-workflow.tsx` under the 300-line guard). `StageTrack` still shows where the
  post sits in its lifecycle.
- **Discussion** (`post-discussion.tsx`) takes `collapsible` / `defaultOpen` / `defaultTab`; the post
  editor folds it to a one-line header (title + comment/log counts) to declutter, and opens it
  straight to the activity log when there's feedback to read (status `changes_requested`, `failed`,
  `partially_published`). Step 24.11 moved it off the page bottom into the **Review & send** step
  (and the read-only left column), not full-width below the grid. The grid planner keeps the
  non-collapsible default.
- **Image studio** on the post editor is a collapsed feature card (`image-studio-section.tsx` —
  Sparkles icon, "Make it visual." title, a one-line "what it does", a count of images already
  generated, and a chevron expand control — collapsed by default so it doesn't bloat the page);
  `ImageStudio` takes `hideHeader` to drop its built-in header there. Step 24.11 placed it in the
  **Media** step (it makes media) rather than full-width at the page bottom. The AI-idea page renders
  it expanded as before.
- **Visual feedback** on the post editor is likewise folded into a `Collapsible`
  (`components/post-editor/editor/feedback-section.tsx` — MessageSquarePlus icon, "Feedback" title, a
  one-line hint: note count, "start a review", or "none yet", plus a "N to resolve" badge) so the
  board no longer sits open and dominates the page. Step 24.11 moved it into the **Review & send**
  step (and the read-only left column). It opens on click, and auto-opens (`defaultOpen`) only when
  there's unresolved feedback waiting. `FeedbackBoard` takes `hideHeader` to drop its built-in
  "Feedback" row there; the portal and review-link surfaces keep it. It renders only when there's
  feedback or the viewer can comment.
- Schedule rows use the shared `DateTimePicker`: the account's clock is labelled and, when it
  differs from the viewer's, the same moment on the viewer's clock is shown. The date can't be set in
  the past — the row passes `min={todayIn(account.timezone)}` (`lib/timezones.ts`), so the calendar
  disables days before today on the account's own clock. The time is two dropdowns — hour and
  quarter-hour minute (`time-field.tsx`, styled native `<select>`s — easy on desktop with type-ahead,
  native wheels on phones) rather than the fiddly native time spinner; picking an hour defaults the
  minutes to `:00`, and an off-grid stored minute (best-time or older post) is injected so it still
  shows. "Same local time" (copy) and the explicit spec §4.6
  opt-in "Same moment" (convert one instant into every other channel's own timezone) are separate,
  per-click actions; rows are still stored as local date + time.
- Shortcuts: ⌘/Ctrl+S save, ⌘/Ctrl+Enter opens "Send for internal review" (or "Send to client")
  — still confirmed in the dialog — ⌘/Ctrl+K palette, `?` lists them.

## Routes / Controllers
- `POST /clients/{client}/posts` → `clients.posts.store` (`PostController::store`) — validates via
  `StorePostRequest`, authorizes via `PostPolicy::create`, delegates to `CreatePostAction`, flashes a
  "Post created." toast and redirects straight to the new post's editor (`posts.edit`). The create
  form is the `NewPostDialog` on the client's Posts tab (`clients.posts.index`); existing posts open
  in the editor by clicking anywhere on their card, which navigates the whole row like a link.
- `GET /posts/{post}/edit` → `posts.edit` (`PostController::edit`, Step 0.10) — authorizes via
  `PostPolicy::view`, renders the `posts/edit` Inertia page with the full editor payload: post
  content/targets/assets, the freshly-evaluated `§6` checklist, `targetAccounts` (client's social
  accounts, each with `platform_label`/`caption_limit`/`max_hashtags`/`is_text_only_capable` so
  newly toggled channels get usage counters and live readiness before saving), `allowedTransitions`
  (every `PostStatusTransitionMap::allowedFrom()` entry the acting user also passes
  `PostPolicy::transition` for), `can.update`/`can.comment`/`can.resend_review_email`/
  `can.create_asset` (`AssetPolicy::create` for the post's client — gates the Media section's inline
  "Upload asset" widget, see below),
  `hashtagSuggestions` (bare tags, no `#`) — the client's `BrandBrain::hashtag_policy.always_use`
  list plus every hashtag already used on the client's other posts, deduped and capped at 50 — fed
  into the editor's Instagram-style hashtag picker (`PostController::hashtagSuggestions()`), and
  `mentionableUsers` (`{id, name}[]`, from `PostNotificationService::mentionableRecipients()`) fed
  into the comment box's `@`-mention autocomplete.
- `PUT /posts/{post}` → `posts.update` (`PostController::update`, Step 0.10) — validates via
  `UpdatePostRequest`, authorizes via `PostPolicy::update`, delegates to `UpdatePostAction`, flashes a
  `toast` ("Post saved."), redirects back to `posts.edit`.
- `POST /posts/{post}/reschedule` → `posts.reschedule` (`PostController::reschedule`, also mirrored
  as `preview.posts.reschedule`, which the preview middleware 403s) — body `{days: int}` (non-zero,
  ±366). `ReschedulePostRequest` authorizes via `PostPolicy::reschedule`; delegates to
  `ReschedulePostAction`, flashes a "Post moved." toast and redirects `back()` (so the calendar keeps
  its query-string filters).
- `POST /post-targets/{target}/reschedule` → `posts.targets.reschedule`
  (`PostController::rescheduleTarget`, Step M-5.7 / I-5.1, mirrored as `preview.posts.targets.reschedule`
  which the preview middleware 403s) — body `{scheduled_local_date: Y-m-d, scheduled_local_time?: H:i}`.
  `RescheduleTargetRequest` authorizes via `PostPolicy::reschedule` on the target's post; delegates to
  `RescheduleTargetAction`, flashes a "Channel moved." toast and redirects `back()`.
- `POST /posts/{post}/transition` → `posts.transition` (`PostController::transition`) — body
  `{to: <PostStatus value>, comment?: string}`. `TransitionPostRequest::authorize()` checks
  `PostPolicy::transition($user, $post, $to)`; its `rules()` rejects an illegal `to` value via a
  closure that checks `PostStatusTransitionMap::isAllowed()`, and (Step 0.10) additionally re-runs
  `EvaluatePostChecklistAction` and rejects the request when `to === PostStatus::Scheduled` and the
  checklist hasn't passed (surfaced as a normal 422 validation error on the `to` field either way).
  Delegates to `TransitionPostStatusAction`, flashes a `toast` ("Post moved to <label>."), redirects
  to `posts.edit`.
- `POST /posts/{post}/comments` → `posts.comments.store` (`PostController::comment`) — body
  `{body: string, internal_only?: bool}`. `StorePostCommentRequest::authorize()` checks
  `PostPolicy::comment` (same visibility as `view`). Delegates to `CreatePostCommentAction`, flashes a
  `toast` ("Comment posted."), redirects to `posts.edit`. `body` may contain `@[Name](id)` mention tokens — see “`@`-mentions” below and
  `.junie/modules/notifications.md`.
- `POST /posts/{post}/activity-logs/{activityLog}/resend-review-email` →
  `posts.activity-logs.resend-review-email` (`PostController::resendReviewEmail`) — authorizes via
  `PostPolicy::resendReviewEmail` (same roles as `update`), 404s if the `activityLog` doesn't belong
  to `post`, delegates to `ResendClientReviewEmailAction`, redirects back to `posts.edit`. Surfaced in
  the editor's activity log entries (`posts/edit.tsx`) as a "Resend email" button next to the stored
  review link, visible only to users with `can.resend_review_email`.
- `POST /posts/{post}/review-link` → `posts.review-link` (`PostController::reviewLink`) — UX overhaul
  (post-editor journey). Same gate as resend (`PostPolicy::resendReviewEmail`); delegates to
  `CreateShareableReviewLinkAction` and returns `{url}` JSON (the plaintext link, shown once). Mints a
  fresh review token for a `waiting_client` post (422 otherwise) and revokes the post's previous
  tokens — the security model of the review email (Step I-1.6) — so the studio can copy a link to
  share over any channel, including when the client has no email on file. Surfaced in
  `post-editor/workflow/workflow-rows.tsx` (`ReviewLinkRow`): a "Copy review link" button beside the
  existing "Resend email", shown for any `waiting_client` post the viewer can manage.
- `GET /clients/{client}` → `ClientController::show` passes `posts` (each post with its `targets`,
  including `social_account_id`/`platform`/`handle`/`scheduled_local_date`/`scheduled_local_time`/
  `scheduled_at_utc`) and `targetAccounts` (the client's social accounts, usable as post targets) to
  the `clients/show` Inertia page, gated behind `PostPolicy::viewAny`/`can.createPost`. Each post card
  now links to the Step 0.10 editor (`posts.edit`) for status/comments/transition/checklist controls.
- `GET /review/{token}` → `review.show` (`ReviewController::show`, Step 0.13) — **no auth
  middleware**; authorization is purely “do you possess a valid, unexpired, unrevoked `ReviewToken`
  whose hash matches this `{token}`”. Resolves the token's post (the token's own `post_id` if set,
  otherwise the client's latest `waiting_client` post), 404s (`NotFoundHttpException`) on any invalid/
  revoked/expired/cross-client token, and renders the `review/show` Inertia page with a minimal,
  money-free payload (`client.name`, and `post.{id,status,status_label,master_caption,review_message,
  hashtags,assets,targets,can_decide,comments}` — no `retainer_amount` or any other client field).
  `post.comments` is the post's `PostComment`s filtered to `internal_only = false` only, so internal
  notes never leak through this unauthenticated endpoint.
- `POST /review/{token}` → `review.decide` (`ReviewController::decide`, Step 0.13) — body
  `{decision: 'approved'|'changes_requested', comment?: string}` via `ReviewDecisionRequest`. Re-
  resolves and re-validates the token exactly like `show`, then calls `TransitionPostStatusAction`
  with a `null` user (so the resulting `PostActivityLog`/`PostApproval` rows record a null
  `user_id` — there is no authenticated reviewer session in this flow), redirects back to
  `review.show`.

## Business Rules
- **Disconnected channels drop on save.** A target whose social account was soft-deleted isn't in
  `targetAccounts`, so the editor leaves it out of its editable channels (`initialEditorState` in
  `pages/posts/edit.tsx`) and the next save removes it. `UpdatePostRequest` still rejects a
  soft-deleted `targets.*.social_account_id` if one is sent.
- **Image format & framing (live preview).** Each channel has a format — `MediaRatio`: `original`,
  `1:1`, `4:5`, `3:4`, `1.91:1`, `9:16`; offered per platform by `MediaRatio::optionsFor()`
  (Instagram: original/1:1/4:5/3:4/1.91:1 · TikTok: original/9:16 · Facebook & LinkedIn:
  original/1:1/4:5/1.91:1). Default is `original` everywhere, so older posts publish exactly as
  before. Each attached image has one focus point + zoom shared by every channel. A fixed format
  crops each image to the largest box of that ratio, shrunk by zoom, centred on the focus point and
  clamped inside the image (`App\Support\Media\CropBox`, mirrored in `resources/js/lib/media-format.ts`
  `cropBox()` — keep them in step). `original` leaves images alone, except on Instagram, whose API
  rejects anything outside 4:5–1.91:1 and frames a carousel at its first image's shape — there the
  publisher cuts every image to the first image's ratio clamped into that range. Videos always
  publish as uploaded. The previews (`SocialPostPreview`) apply the same rules through
  `frameFor()`, so editor, review link and client portal show what will publish. Saving sends
  `media_format[platform]` and `asset_crops[assetId][x|y|zoom]` with the editor form;
  `UpdatePostRequest` only accepts formats the channel offers and framing for attached media.
- **The preview shows the published caption.** `publishedCaption()` mirrors
  `UploadPostAdapter::applyHashtagCaptionOverride()` (caption, eight-line dash spacer, `#tags`), and
  each platform folds it where the app does (`captionFolds`: Instagram 125 chars/2 lines, TikTok
  80/2, Facebook 240/5, LinkedIn 210/3 — approximations) with a working "more" control; the panel
  shows how much of the caption sits before the fold.
- **Right-to-left captions (Arabic, Hebrew, …).** Caption direction is decided per text by its first
  strong character — never by a user/org setting — so an Arabic caption renders RTL and an English
  caption containing an Arabic word stays LTR. Editable fields (caption textarea, hashtag input,
  location) and plain caption/AI-suggestion displays use `dir="auto"` (Windows/Chrome users can
  also force direction with Ctrl+Left/Right Shift in the textarea); the platform previews in
  `social-post-preview.tsx` set `dir` on the whole caption block (handle included) via
  `textDirection()` in `resources/js/lib/text-direction.ts`, matching how the platforms lay it out.
  Hashtag chips use `dir="auto"` so `#` sits on the right of Arabic tags. Backend storage/validation
  is already Unicode-safe (MySQL `utf8mb4`, `\p{L}` regexes); Arabic glyphs render via the
  font-stack fallback (Arial/system-ui) because Poppins has no Arabic coverage.
- `Post::booted()` normalizes `hashtags` on every save: strips any leading `#`(s), trims
  whitespace, drops empty entries, and dedupes — so it doesn't matter whether a user types
  `#launch` or `launch`, the stored/returned/sent value is always the bare tag (`launch`). Every
  consumer that needs the `#` back (the review portal, `UploadPostAdapter`) is expected to prefix
  it at render/send time rather than assume it's already there.
- `PostPolicy` mirrors `CampaignPolicy`: `viewAny`/`view` scoped by `org_id` (Client reviewer scoped to
  their own `client_id`); `create`/`update` allowed for Owner/Strategist/Designer; `delete` restricted
  to Owner/Strategist. Registered in `AppServiceProvider::boot()`.
- `PostPolicy::reschedule` = `update` (so Owner/Strategist/Designer, same org, not `scheduled`/
  `published`) **and** not `publishing`/`archived` **and** no target already `PostTargetStatus::
  Published`. `scheduled` stays locked because its `PublishPostJob` is already queued with a delay for
  the old time — move it back out of `scheduled` first.
- `PostPolicy::update` also locks a post's content once it reaches `scheduled` or `published` — it
  returns `false` regardless of role while the post is in either status, so `UpdatePostRequest`
  (`posts.update`) 403s and `PostController::edit`'s `can.update` flag goes `false`. `view`/`transition`
  are unaffected, so the post can still be opened and moved to a different status (e.g. back to
  `changes_requested`, or `archived`) to unlock editing again. `resendReviewEmail` delegates to
  `update`, so it's locked too, though it's only ever relevant while `waiting_client`.
- `PostPolicy::transition($user, $post, $to)`: Owner/Strategist/Designer within the post's org can
  request any (state-machine-legal) transition. A `Role::ClientReviewer` may only transition a post
  that (a) belongs to their own `client_id` and (b) is currently `waiting_client`, and only to
  `approved` or `changes_requested` — this is the review-portal action set that Step 0.13 will expose
  through a `ReviewToken` instead of a logged-in session.
- `PostPolicy::comment($user, $post)`: same visibility as `view` (i.e. `viewAny` for the post's
  client) — anyone who can see the post can add a comment.
- `review_message` is shown to the client on both the review portal (`review/show.tsx`, above the
  master caption, in a highlighted box) and in `ClientReviewRequestedMail`
  (`resources/views/emails/client-review-requested.blade.php`), whenever a post enters
  `waiting_client` — it's read on-demand from `Post::review_message` at send/render time, so editing
  it after the email went out still updates what's shown on the review portal.
- `CreatePostCommentAction` always forces `internal_only = false` for `Role::ClientReviewer`
  commenters, independent of client input, so internal notes never leak through this endpoint.
- **`@`-mentions in status dialogs**: the transition note and the approval-override reason use
  `MentionTextarea` (`PostWorkflow` gets `mentionableUsers`). `TransitionPostStatusAction` resolves the
  tokens after commit against org recipients only (never the actor, never outsiders/client users) and
  sends `PostNoteMentioned`; activity-log notes render mentions via `renderCommentBody`.
- **`@`-mentions**: a comment's `body` may embed `@[Name](id)` tokens; `CreatePostCommentAction`
  resolves them (via `CommentMentionParser` + `PostNotificationService::mentionableRecipients`,
  which is org users + the post's client-portal users) into `PostComment::mentionedUsers()` rows
  and sends each one a `PostCommentMentioned` notification, regardless of `internal_only` or which
  “side” they're on. Full detail lives in `.junie/modules/notifications.md`; the comment UI itself
  is `resources/js/components/mention-textarea.tsx` (`MentionTextarea`/`renderCommentBody`), used
  by both `posts/edit.tsx` and `portal/posts/show.tsx`.
- The state machine (`PostStatusTransitionMap`) is enforced in two independent places: the
  `TransitionPostRequest` validation rule (so illegal moves surface as a normal 422/validation error)
  and defensively inside `TransitionPostStatusAction` itself (throws `InvalidArgumentException` if
  ever called with an illegal move bypassing the request layer).
- Frontend access: the Posts module is reachable from the client detail page (`resources/js/pages/
  clients/show.tsx`), in a collapsible "Posts" section alongside Social accounts/Campaigns/Assets.
  Users with `can.createPost` see a quick create form (caption, toggle-selectable target accounts,
  one shared local date/time applied to all selected targets' hidden `targets[i][...]` inputs — each
  target still gets its own independently-computed `scheduled_at_utc` server-side) submitted via
  `PostController.store.form`. Existing posts are listed with status badge, caption, a chip per
  target showing handle + local date/time. Each post card is itself the clickable affordance into
  the full Step 0.10 editor (`resources/js/pages/posts/edit.tsx`, route `posts.edit`) — the whole
  row is `role="button"`/keyboard-focusable and calls `router.visit(editPost(post.id).url)` on
  click or Enter/Space, with a hover border/background treatment and a small non-interactive
  "Open editor" pencil hint on the right (no separate button/link element to avoid nested
  interactive controls).
- Hashtags are entered via `resources/js/components/hashtag-input.tsx` (`HashtagInput`), an
  Instagram-style tag picker: existing tags render as removable chips, typing filters
  `hashtagSuggestions` to a matching dropdown (click or Enter/arrow-keys to pick), and Enter/comma
  on free text adds it as a new tag. Any leading `#` typed by the user is stripped client-side
  before it's added (and `Post::booted()` normalizes it again server-side as a backstop), so it no
  longer matters whether someone types `#launch` or `launch`.
- The Step 0.10 editor page (`posts/edit.tsx`, redesigned 2026-10-16, reorganised 2026-10-19 as
  "one composer, every channel") is a single page, no tabs: `PageHeader` (client name, status pill,
  campaign chip) → the **workflow panel** (`components/post-editor/post-workflow.tsx`) → a two-column
  body. Left, numbered panels:
  - **01 Write** — `ChannelScopeBar` + `Composer` (`components/post-editor/composer.tsx`). The scope
    bar is the editor's *one* channel switcher: "All channels" edits the master caption/hashtags/
    link/first comment (AI suggestions live here); picking a channel shows the same fields as that
    channel will publish them. Typing there creates an override (`ChannelState`), each field carries
    a "Same as all channels" / "Only on {platform}" tag with Reset, and a value typed back to match
    the master falls back to inheriting (`null`). Format (Auto/Reel/Story…) is set in channel
    scope. Pills mark customized channels with a dot and over-limit channels with "Too long". The
    caption meter shows the tightest inheriting channel in All scope, that channel's limit
    otherwise. Link and first comment hide behind "+ Link" / "+ First comment" chips until used.
    The composer's inputs are unnamed — hidden `master_caption`, `hashtags[i]`, `link_url`,
    `first_comment` inputs submit the master; `TargetFields` (`target-fields.tsx`) submits overrides.
  - **02 Where & when** — `ChannelSchedule`, one row per client social account with a checkbox, its
    own local date/time, and caption/hashtag usage against that platform's limits.
  - **03 Media** — `MediaPicker`, only the *attached* assets in publish order (numbered, removable)
    followed by two tiles — Upload (click or drop; posts JSON to `clients.assets.store`) and Browse
    library (`UploadTile` / `BrowseLibraryTile`, also reused by the client Assets tab), which opens
    `AssetLibraryDialog`: filename search (debounced), All/Images/Videos/Documents filter,
    24-per-page pagination via `clients.assets.index` (see `assets.md`), and a draft multi-selection
    applied on "Attach" (existing picks keep their order, new ones append). The editor no longer
    preloads the client's library (`availableAssets` prop removed); it only keeps details for assets
    in use. `MediaDetails` (alt text, video cover/frame) follows.
  - **04 Post settings** — folded by default to a one-line spec summary (campaign · approval ·
    sound · location); "Edit" or clicking the summary opens `CampaignField`, approval mode,
    `MusicField` and location. The content is `forceMount`ed so its inputs (incl. `MusicField`'s
    hidden `music[...]`) still submit while folded.
  Right, a sticky rail: `ReadinessChecklist` (open items first, done items folded behind "Show N
  done"; every item is a jump link — `checkSection` in `edit.tsx` maps check keys to a section,
  which scrolls into view with a brief focus ring, opens Post settings, or focuses the caption) and
  `PreviewPanel`, whose active channel follows the composer scope (and, while a channel is scoped,
  switching the preview switches the composer too). `PostDiscussion` (Comments / Activity tabs)
  sits under the composer. All hidden form input names (`targets[i][...]`, `asset_ids[i]`,
  `hashtags[i]`, `music[...]`, `location[name]`) are unchanged. Read-only viewers (`can.update:
  false`, incl. the scheduled/published lock) get read-only panels and a lock notice.
  - **Workflow panel.** A six-stage track (Draft → Internal review → Client review → Approved →
    Scheduled → Live; `lib/post-workflow.ts` maps every `PostStatus` onto a stage, archived dims the
    track) plus a one-line guidance headline for the current status. `allowedTransitions` from the
    server are turned into verb-labelled actions (`describeTransition`, e.g. "Send to client",
    "Approve without client", "Back to draft"): the single recommended next step
    (`primaryTransitionFor`, which picks `approved` over `waiting_client` from internal review when
    `approval_mode` is `internal_only`) is the primary (Ink) button, other forward moves are outline
    buttons, and backward/manual-override/archive moves sit in a "More" menu. Every move opens a
    confirm dialog showing from → to pills, an optional **note** (sent as the transition `comment`,
    stored on the activity log), the failing checklist items when scheduling is blocked (confirm
    disabled — server stays the gate), and an unsaved-edits warning. While `waiting_client`, the
    latest `review_url` is shown with Copy and (with `can.resend_review_email`) Resend buttons.
    `lib/post-workflow.ts` is presentation only — `PostStatusTransitionMap` remains the source of
    truth for legality.
  - **Unsaved changes.** The page compares its local state to the props snapshot to derive
    `isDirty`, shows a sticky save bar ("Unsaved changes" / "All changes saved", Discard, Save),
    supports Ctrl/⌘+S, and `hooks/use-unsaved-changes-guard.ts` holds any real Inertia GET
    navigation behind the styled `confirmDialog` ("Leave without saving?") and re-issues it only
    if the user chooses to leave. Tab close/refresh is not guarded (only a native browser prompt is
    possible there, and the app avoids native dialogs). Applying an AI caption over an existing one
    also confirms via `confirmDialog`. The update/transition forms use `preserveState: 'errors'` (edits survive
    a validation error; the page remounts with fresh props on success); comment and resend-email
    forms use `preserveState: true` so posting a comment never discards unsaved edits.
  - **Live readiness.** `liveChecklist()` mirrors `EvaluatePostChecklistAction` against unsaved
    state (using `targetAccounts[].is_text_only_capable`) so the checklist reacts while editing;
    the server re-evaluates on save and before any move to `scheduled`.
  - **"Use for all channels"** on a channel row copies that row's date/time into the other
    selected rows on an explicit click — each target is still stored and converted to UTC
    independently (spec §4.6).
  - The Music field (Step 1.8.4) is a live search box shown only while an Instagram/TikTok channel
    is selected (platform toggle when both are) that calls `PostController::musicSearch` (see
    `publishing.md`) and writes `{id, name}` into hidden `music[id]`/`music[name]` inputs.
- Activity entries (`PostDiscussion`) render as a timeline: actor, from → to status pills,
  timestamp, the full note as a quote, and for `waiting_client` hops the review link plus (with
  `can.resend_review_email`) a "Resend email" button posting to `PostController::resendReviewEmail`.
  Comments render in full with an avatar and an "Internal" badge; the composer's internal toggle
  states who will see the comment.
- **Never collapse independent local times.** `scheduled_local_date`/`scheduled_local_time` are stored
  per target as given; `scheduled_at_utc` is derived per target from its own account's timezone. Do not
  introduce any "same instant" shortcut without an explicit opt-in per spec §4.6.
- The exact §4.6 field list (full status-driven business rules, `approvalMode` semantics beyond a
  simple internal/client-required toggle) wasn't available in this repo — see Known Gaps below.

## Tests
- `tests/Feature/PostEditorControllerTest.php::test_update_drops_a_target_whose_account_was_disconnected`
  — a soft-deleted target account is not offered to the editor, re-sending it is rejected, and a
  save without it succeeds and removes the dead target.
- `tests/Feature/ReschedulePostTest.php` — calendar drag-and-drop move: all targets shift and keep
  their local times (incl. a London BST→GMT crossing re-deriving UTC), reminder reset, moving
  earlier, past/today-already-passed rejections, `days` validation, Viewer/other-org 403s, every
  locked status (`scheduled`/`publishing`/`published`/`archived`) and an already-published target
  403, preview-mode 403, and the calendar's `reschedulablePostIds` prop.
- `tests/Unit/Models/PostTest.php` — regression coverage for `Post::booted()`'s hashtag
  normalization: mixed `#Tag`/`tag`/whitespace/duplicate/empty entries collapse to the expected
  deduped, `#`-stripped list; `null` hashtags are left untouched.
- `tests/Unit/Actions/EvaluatePostChecklistActionTest.php` — caption/targets/media checklist rules,
  including the text-only-capable-platform exemption from media (LinkedIn) and that a non-text-only
  platform (Instagram) requires an attached asset to pass.
- `tests/Feature/PostEditorControllerTest.php` — the Step 0.10 editor endpoints: `posts.edit`
  exposes the checklist + only-authorized `allowedTransitions`; `posts.update` fully replaces targets
  and assets and refreshes `checklist_snapshot`; cross-org designers are forbidden from opening the
  editor. `test_scheduled_and_published_posts_are_locked_for_editing` and
  `test_updating_a_scheduled_or_published_post_is_forbidden` cover the editor-lock rule: an
  authorized owner still gets `can.update: false` on `posts.edit` and a 403 on `posts.update` while
  the post is `scheduled`/`published`, with the caption left untouched.
  `test_editor_exposes_hashtag_suggestions_from_brand_brain_and_past_posts` asserts
  `hashtagSuggestions` merges the client's `BrandBrain::hashtag_policy.always_use` list with
  hashtags used on the client's other posts, deduped and `#`-stripped.
  `test_update_preserves_arabic_rtl_caption_and_hashtags` round-trips a mixed Arabic/English
  caption (emoji, line break, Arabic-Indic digits) and Arabic hashtags through `posts.update` and
  back out of the `posts.edit` payload unchanged.
  `test_music_search_returns_tracks_from_the_music_provider_for_tiktok` and
  `test_music_search_short_circuits_for_platforms_without_a_music_lookup` (Step 1.8.4) cover
  `posts.music-search` — see `publishing.md` for `PostController::musicSearch` itself.
- `tests/Feature/PostControllerTest.php`:
  - `test_targets_on_different_timezone_accounts_keep_independent_local_times` — the §12 acceptance
    test: creates a post with two targets (Europe/London and Asia/Dubai social accounts) at the same
    local wall-clock time and asserts both the local date/time and the derived UTC instants stay
    independent (and are not equal to each other).
  - `test_designer_cannot_create_a_post_outside_their_org` — cross-org authorization rejection.
  - `test_client_show_page_exposes_posts_and_target_accounts` — asserts the `clients/show` Inertia
    response includes `posts`/`targetAccounts`/`can.createPost`, confirming the module is reachable
    from the frontend.
- `tests/Feature/PostTransitionControllerTest.php` (Step 0.9's Done-when acceptance coverage — both
  valid and rejected transitions):
  - `test_owner_can_move_a_post_through_the_valid_transition_chain` — walks a post
    `draft → internal_review → waiting_client → approved → scheduled`, asserting each hop persists
    and that exactly one `PostActivityLog` row is created per hop and one `PostApproval` row overall.
  - `test_illegal_transition_is_rejected` — `idea → published` is rejected as a 422 validation error
    on `to`, and no status change/activity log row is created.
  - `test_designer_from_another_org_cannot_transition_a_post` — cross-org authorization rejection.
  - `test_client_reviewer_can_approve_a_post_waiting_on_them_and_it_creates_an_approval_record` —
    asserts the `PostApproval` row (`decision`, `comment`, `user_id`).
  - `test_client_reviewer_cannot_transition_a_post_that_is_not_waiting_on_them` and
    `test_client_reviewer_cannot_review_another_clients_post` — the two review-portal-adjacent
    rejection cases from `PostPolicy::transition`.
  - `test_a_comment_can_be_added_to_a_post_with_an_internal_only_flag` and
    `test_client_reviewer_comments_are_never_stored_as_internal_only` — comment creation and the
    forced-`internal_only = false` rule for client reviewers.
  - `test_scheduling_is_blocked_when_the_checklist_has_not_passed` (Step 0.10) — a post with no
    targets/media is rejected with a 422 on `to` when transitioning to `scheduled`.
- `tests/Unit/Actions/PostStatusTransitionMapTest.php` — asserts every `PostStatus` case has an
  explicit map entry (no silently-missing state), spot-checks a few valid/invalid edges, and checks
  the `published` terminal state can only move to `archived`.
- `tests/Feature/ReviewControllerTest.php` (Step 0.13): asserts a `waiting_client` transition emails
  `ClientReviewRequestedMail` to `Client::approval_email` (and that no mail is sent, without erroring,
  when it's unset); a valid token can view (`can_decide: true`) and `approved`-decide a post via
  `review.decide` (recording a null `user_id` approval); an unknown/revoked token 404s; a token
  never exposes another client's post or any `retainer_amount`/billing field (the §12 isolation test);
  and `test_the_review_message_and_only_non_internal_comments_are_visible_on_the_review_page` asserts
  `post.review_message` is exposed and `post.comments` only includes non-`internal_only` comments.
  Also asserts the `waiting_client` transition's `PostActivityLog::review_url` is populated (and left
  `null` when no email is sent), and covers `posts.activity-logs.resend-review-email`: an org user
  can re-send `ClientReviewRequestedMail` with the stored `review_url`, and a cross-org designer is
  forbidden.
- `tests/Feature/SendUpcomingPostRemindersCommandTest.php`: asserts `posts:send-upcoming-reminders`
  emails `UpcomingPostReminderMail` to every user of the org that owns a `Scheduled` post's target due
  within the `--days` window (and stamps `reminder_sent_at`); asserts a target already stamped with
  `reminder_sent_at` is never re-emailed; asserts non-`Scheduled` posts and targets outside the window
  are ignored entirely.

## Seeders
- `Database\Seeders\DemoContentSeeder`: for each of `ClientSeeder`'s `Recommendo`/`Frzn` clients,
  creates four posts (published/scheduled/waiting_client/draft) each with a `PostTarget`, an attached
  demo `Asset`, one internal-only + one client-visible `PostComment`, and one `PostActivityLog`
  entry — see `social-accounts.md`/`campaigns.md`/`assets.md` for the rest of what it seeds. Called from
  `DatabaseSeeder` after `InvoiceSeeder`.

## Known Gaps / Open Questions
- 2026-10-20: the editor page is still one large file plus new feature components — M-1.6 (editor
  decomposition, I-4.6) hasn't been done yet and should precede more editor work.
- Alt text is per image (shared by every channel), not per channel.
- `ApprovalMode` (`internal_only` | `client_required`) is an interim definition — the exact enum/
  semantics from spec §4.6 weren't available in this repo. It is **not yet consulted** by
  `PostStatusTransitionMap`/`PostPolicy::transition` — e.g. `internal_review → approved` (skipping
  `waiting_client`) is currently allowed for internal roles regardless of `approval_mode`. Wiring the
  map/policy to branch on `approval_mode` is a likely follow-up once the exact §4.6 semantics are
  confirmed.
- The exact §6 checklist rule list and §9 editor field set (e.g. structured `location`/`music`
  objects with vendor-specific ids, rather than the interim `{name}` shape used here) weren't
  available in this repo — see the `EvaluatePostChecklistAction`/`Platform` entries above for the
  interim definitions used instead.
- Content edits on a `scheduled` post stay locked by `PostPolicy::update` (Step I-1.2 decision):
  editing approved content in place would bypass the client's approval. Calendar moves of a
  scheduled post are allowed and re-dispatch safely; `UpdatePostAction` re-dispatches too, so the
  lock can be relaxed later (e.g. "edit resets approval") without touching the publish pipeline.
- "Copy review link" was removed from the editor with Step I-1.6 (the plain link is no longer
  stored); "Resend email" mints a fresh link instead. A "copy a fresh link" endpoint is a possible
  follow-up.
- Resolved 2026-10-18 (Step I-1.5): `DELETE posts/{post}` (`DeletePostAction`, a soft delete) with
  a "Delete post" item in the editor's "More" menu. `PostPolicy::delete` (Owner/Strategist) refuses
  `publishing`/`published`/`partially_published` posts and any post with a published target —
  those can only be archived. Deleting a `scheduled` post clears its dispatch id. The Step 0.11 calendar/list view across posts now exists — see `.junie/modules/calendar.md`.
- `PostTarget` has no per-target status/external-publish fields yet; those belong to Phase 1
  (`PublishAdapter`/`TargetResult`, Steps 1.1–1.2) and should be added then, not now.
- No index/listing endpoint for `PostActivityLog`/`PostApproval`/`PostComment` yet; they're currently
  only readable via the `Post` relations (`activityLogs()`/`approvals()`/`comments()`) for the future
  editor UI to render.
- Resolved 2026-10-18 (Step I-1.6): every new `waiting_client` transition and every resend revokes
  the post's older unexpired review tokens, so only the newest link works.
- The review portal has no explicit rate limiting on `/review/{token}` beyond default web middleware
  — worth revisiting since it's unauthenticated and token-guessing-resistant only via token entropy.

## Changelog
- 2026-10-24: UX overhaul (post-editor journey, `.junie/plans/ux-overhaul.md`) — a copyable client
  review link. New `POST /posts/{post}/review-link` (`CreateShareableReviewLinkAction`) mints a fresh
  review token (rotating the post's previous ones, Step I-1.6) and returns the plaintext URL for the
  studio to copy and share over any channel — the one way to get a review link to a client with no
  email on file. `ReviewLinkRow` gains a "Copy review link" button and now shows for any
  `waiting_client` post the viewer can manage (not only when an email was sent). Test:
  `tests/Feature/Posts/ShareableReviewLinkTest.php`. The build-step journey (stepper, review step,
  action bar, `StageTrack`) was already in place from the branch's recent refactor; this is the one
  handoff gap it left.
- 2026-10-23: Step M-5.8 — bulk import, recycling & recurring posts. New `posts` columns
  `is_recyclable` / `recycle_after_days` / `recycled_from_post_id` / `recurrence` (+ `PostRecurrence`
  enum, `recycledFrom`/`recycledInto`). `RecyclePostAction` (manual recycle → idea; recurrence → draft
  with advanced schedule), `GenerateRecurringPostDraftsAction` + `posts:generate-recurring-drafts`
  (daily), Home "Ready to recycle" bucket, `posts.recycle` route + `PostPolicy::recycle`, editor "06
  Repeat & reuse" panel. Phase 10 CSV import: `BulkPostImportController` + `ParseBulkPostImportAction`
  + `CreatePostsInBulkAction` + `BulkPostRow`/`BulkImportParseResult`, `bulk-import.tsx`, "Import CSV"
  on the client Posts tab. Tests `tests/Feature/Posts/RecyclePostTest.php`, `BulkPostImportTest.php`.
  See "Phase M-5 › M-5.8", `home.md`, `calendar.md`.
- 2026-10-23: Content hub toolbar UX — the six filter dropdowns + date range are consolidated into a
  single **Filters** slide-over (`Sheet`, with an active-count badge); active filters show as
  individually removable chips below the row, leaving just Search · Filters · Views · Clear. A Sheet is
  used (not a Popover) so the nested `Select` menus — which run with `avoidCollisions={false}` — have
  room for long client/campaign labels instead of spilling outside a narrow panel. The table view's
  controls (search · Filters · Views) are passed as the `DataTable` `toolbar` so they share one row
  with its built-in **Columns** menu (the table always renders via `DataTable`'s `empty` slot, keeping
  the search box mounted so it never loses focus on an empty result). Backend/props unchanged
  (`tests/Feature/Content` still green).
- 2026-10-23: Step M-5.4 — assignees, due dates & "My work". `posts.assignee_id`/`reviewer_id`/`due_at`
  (+ relations, requests, editor Assignment panel); internal-review notifications target the
  reviewer/assignee (`PostNotificationService`); `/my-work` (`MyWorkController` + `GetMyWorkAction`,
  sidebar + palette); content hub assignee filter/column + bulk Assign; deliverable → post assignee/due
  handoff. Tests `tests/Feature/Posts/PostAssignmentTest.php`, `MyWorkTest.php`. See "Phase M-5 › M-5.4".
- 2026-10-23: Step M-5.3 — autosave, version history & "what changed". New `post_revisions` +
  `PostRevision`, `PostRevisionService` (snapshot/record/diff/changesSinceLastReview),
  `RestorePostRevisionAction`, `PostRevisionController` (+ routes); `UpdatePostAction` takes `$actor`
  and records a revision per content-changing save, `TransitionPostStatusAction` records a shared
  checkpoint on `waiting_client`. Editor: 5s autosave (`use-autosave`), "Saved · HH:MM" in `SaveBar`,
  a History drawer (`post-history-drawer`), image-studio block extracted to `image-studio-section`.
  Portal + review link show "what changed since your last review" (`changes_since_review`,
  `review-changes`, `lib/post-diff`). `BatchReviewCampaignPostsAction` staleness now content-aware.
  Tests `tests/Feature/Posts/PostRevisionTest.php`. See "Phase M-5 › M-5.3".
- 2026-10-23: Step M-5.2 — faster creation & duplicate. `DuplicatePostAction` +
  `POST posts/{post}/duplicate` (copies content, media and target accounts into a fresh draft, never
  the schedule/status); "Duplicate" in the editor header (via editor context `can.duplicate`) and the
  content-hub row menu; a "New post for…" client picker on the hub and an agency command-palette "New
  post"; `can.duplicate` added to the editor and content-hub presenters. Tests
  `tests/Feature/Posts/DuplicatePostTest.php` + a campaign-prefill case in `PostControllerTest`.
- 2026-10-23: Step M-5.1 — the agency content hub (`/content`): org-wide table + status board,
  server-side filters, a bulk-action bar (move / archive / add to campaign / reschedule, each
  reporting skips), CSV export and personal saved views. New `ListPostsAction`,
  `GetContentBoardAction`, `BulkUpdatePostsAction`, `ContentHubPresenter`, `SavedPostView` (+ policy,
  factory, `saved_post_views`), `PostPolicy::viewHub`, `ContentHubController`,
  `SavedPostViewController`, `resources/js/pages/content/index.tsx`. See the "Phase M-5 additions"
  section. Tests in `tests/Feature/Content/`.
- 2026-10-21: `@`-mention "tags" in the status dialogs. The transition dialog's note and the
  Owner's approval-override reason now use `MentionTextarea` (`PostWorkflow`/`TransitionDialog`/
  `OverrideApprovalDialog` take `mentionableUsers`); `TransitionPostStatusAction` resolves the
  tokens after commit against org recipients only and sends `PostNoteMentioned` to each tagged
  teammate (de-duped from the broad status audience). Activity-log notes render tokens via
  `renderCommentBody`. See the "`@`-mentions in status dialogs" rule above and
  `.junie/modules/notifications.md`.
- 2026-10-21: Step M-3.2 — best-time chips in the editor's channel rows; Step M-3.4 — status changes queue a client health recompute.
- 2026-10-20: Phase 18 (grid planner) — `Post::gridTile()` (HasOne); a deleted post frees its tile (`Post::booted()`), and a grid post's status change re-checks its grid's order (`GridOrderRiskService`). The editor shows a "From grid: {name} · Tile 4 · Goes out 3rd" chip (`grid` prop, `GridLibrary::postChip()`). `EvaluatePostChecklistAction` adds soft `grid_match` (media/framing/format still match the approved tile — `GridDriftService`) and `grid_order` (still in the grid's publish order — `GridSequenceValidator`). The review link and the portal post page say "You approved how this looks in {grid}" (`grid_name`). The campaign bulk move now runs through the shared `App\Actions\Posts\BulkTransitionPostsAction` (which also refuses to schedule a post whose checklist fails). `ReviewController::show` branches on grid tokens (see `grid-planner.md`); a grid token never decides a post. `PostDiscussion` takes optional `commentForm`, `statusKind`, `renderChip`, `onCommentHover`, `composerExtra` (defaults unchanged).
- 2026-10-20: Fix — saving a post whose target account had been disconnected failed with "The
  selected targets.0.social_account_id is invalid." The editor re-sent the hidden dead target; it
  now only edits targets whose account is selectable. Regression test:
  `PostEditorControllerTest::test_update_drops_a_target_whose_account_was_disconnected`.
- 2026-10-19: Post editor reorganised for UX ("one composer, every channel"). The separate
  "Customize per channel" panel and the preview's own channel switcher are replaced by one
  `ChannelScopeBar` that drives the new `Composer` (master vs per-channel override editing) and the
  preview. Link/first comment hide behind add-chips; campaign/approval/sound/location fold into a
  "Post settings" summary; the readiness checklist folds done items and jumps to the section that
  fixes each item. `channel-customizer.tsx` → `target-fields.tsx` (only `TargetFields` remains).
  Header no longer repeats per-channel schedule chips. Frontend only — no request/response changes.
- 2026-10-20: Phase M-2 — per-channel overrides, formats, registry-driven checklist with details and
  fixes, approval mode enforcement + Owner override, recall/resubmit of vendor-held posts. Tests:
  `ChannelOverridesTest`, `PostFormatsTest`, `ApprovalModeTest`, `VendorHeldPostsTest`.
- 2026-10-19: Live preview overhaul + image format/framing. New `MediaRatio` enum, `CropBox`,
  `PublishImageCropper` (see `publishing.md`), `PostMediaPresenter` (format map, per-channel options,
  asset size + framing for every preview payload — editor, `review/show`, portal post, portal
  campaign review); migration `2026_10_19_110000_add_media_format_to_posts` (`posts.media_format`,
  `post_assets.crop_*`). `UpdatePostAction` stores `media_format` (empty → null) and syncs
  `asset_crops` onto the pivot (an attached image without framing resets to centred; saving media
  without `asset_crops` keeps existing framing). Frontend: `social-post-preview.tsx` rebuilt per
  platform — Instagram feed (carousel counter + dots, location/sound line, "more" fold) and Reels
  (video), TikTok (video + swipeable photo mode, letterboxed `original`), Facebook and LinkedIn
  (page name from `SocialAccount::display_name`, "See more"/"…more", 2/3/4+ image grids, LinkedIn
  Repost/Send row); documents are no longer drawn as media; hashtags/mentions/links styled per app;
  optional TikTok/Reels safe-zone overlay. `PreviewPanel` gains a per-channel Format picker,
  contextual notes (Instagram range/carousel, video, documents, LinkedIn AI credentials), a caption
  fold meter, and `CropEditorDialog` (drag the crop box / arrow keys, zoom, per-image focus, live
  "On every channel" thumbnails; draft until "Apply framing", saved with the post). Bugfix: the
  editor now sends an empty `asset_ids`/`targets` field when the last media item or channel is
  removed, so saving actually detaches it (previously the browser omitted the empty list and the
  old rows stayed). Tests: `tests/Feature/Posts/PostMediaFormatTest.php`,
  `tests/Unit/Support/Media/CropBoxTest.php`, `tests/Unit/Enums/MediaRatioTest.php`.
- 2026-10-18: Campaigns enhancement — `posts.campaign_id` is editable in the editor (`CampaignField`, `campaignOptions`; `StorePostRequest`/`UpdatePostRequest` only accept the post's own client's campaigns) and posts carry a campaign chip. New transition Scheduled → Approved ("Unschedule") with no approval row. `EvaluatePostChecklistAction` adds a **soft** `campaign_hashtags` item (`soft: true`; `passed` ignores soft items). Deleting a post, or moving it out of its campaign, releases its deliverable. The first published post of a campaign triggers `MarkCampaignWentLiveAction` after commit. See `campaigns.md`.
- 2026-10-18: `ListClientPostsAction` + `ClientPostsTabRequest` — the client page's Posts tab is
  filtered (search/status/campaign/channel) and paged 8 at a time in SQL (see `clients.md`).
- 2026-10-18: Step I-2.6 — `client_hashtags` (`ClientHashtag`: `client_id`, `tag`, lowercased
  `tag_key` unique per client so MySQL's case-insensitive collation and SQLite agree, `uses`,
  `last_used_at`), backfilled from existing posts and kept current by `ClientHashtagService` from
  `Post` model events (created / hashtags changed / deleted / restored; a purge of an
  already-trashed post isn't counted twice). The editor's `hashtagSuggestions` are the Brand Brain
  `always_use` tags then the top 50 by uses and recency. `PostController::edit` eager-loads
  `client.brandBrain`. Shared props: `auth.user` is an explicit whitelist; shared-prop queries are
  ≤ 2 per request (asserted in `tests/Feature/SharedPropsTest.php`). `preventLazyLoading` and
  `preventSilentlyDiscardingAttributes` are on outside production — the suite surfaced one test
  passing a non-fillable `updated_at`. Step I-2.3: `Post::scopeReschedulable`. Step I-2.8:
  `PublishPostJob`/`PublishPostTargetJob` run on the `publishing` queue. Tests:
  `tests/Unit/Services/ClientHashtagServiceTest.php`. Create/delete redirects go to the client's
  Posts tab (`clients.posts.index`).
- 2026-10-18: Step I-1.8 — `SendUpcomingPostReminderEmailsAction` processes one organization at a
  time (only org ids are loaded up front); `posts:send-upcoming-reminders` is overlap-protected
  and single-server with a heartbeat (see `super-admin.md`).
- 2026-10-18: Step I-1.7 — `review/{token}` (GET/POST) and music search are throttled (`public-token`, `music-search`). Named limiters live in `App\Support\RateLimits` (registered in `AppServiceProvider::boot()`):
  `ai` (10/min per user + 60/min per org — trends, ideas, captions), `ai-images` (6/min user +
  30/min org — Image Studio generate/refine/regenerate), `music-search` (30/min per user),
  `exports` (10/min per user — invoice/analytics/report CSV+PDF exports and invoice PDF
  generation), `public-token` (20/min per IP + token and 60/min per IP — review links, client and
  staff invites) and `webhooks` (120/min per IP). All answer with one structured 429
  (`{message, retry_after}` + `Retry-After`; Inertia visits get the message as an error toast).
  Tests: `tests/Feature/Security/RateLimitingTest.php`.
- 2026-10-18: Step I-1.5 — `Post` uses `SoftDeletes`; post delete route + editor action (see Known
  Gaps); `Post::forActiveClients()` scope hides an archived client's posts from working lists.
  Tests: `tests/Feature/Posts/PostDeleteTest.php` (each role, published → archive only, scheduled
  delete stops its job).
- 2026-10-18: Steps I-1.2 / I-1.3 / I-1.4 / I-1.6 (review links). New `partially_published` status
  (map: `publishing → partially_published`, `partially_published → publishing|archived`,
  `failed → publishing` — the last two only via `RetryFailedPostTargetsAction`). Transitions lock
  the row and re-check (`InvalidPostTransitionException`, 409), and run the client email,
  notifications and publish dispatch after commit. Scheduled posts can be rescheduled from the
  calendar (re-dispatch with a fresh `publish_dispatch_id`). `post_activity_logs.review_url`
  dropped for `review_token_id`; `SendClientReviewRequestedEmailAction` revokes older tokens, mints
  a fresh one and queues the mail after commit; `ResendClientReviewEmailAction` mints a fresh link
  (only while the post is still `waiting_client`). Editor: "Retry failed channels" row, review-link
  row without the raw link. Tests: `TransitionAtomicityTest`, `PublishPipelineTest`,
  `ReviewControllerTest` (fresh token + old link 404 + no resend after a decision),
  `ReschedulePostTest` (scheduled posts now movable).
- 2026-10-18: Post editor supports Arabic/RTL text — `dir="auto"` on the caption, hashtag and
  location inputs, read-only caption, AI suggestions and the review page caption; platform
  previews follow the caption's direction via the new `lib/text-direction.ts`. Added
  `test_update_preserves_arabic_rtl_caption_and_hashtags`.
- 2026-10-17: Added `ReschedulePostAction`, `ReschedulePostRequest`, `PostPolicy::reschedule` and
  `PostController::reschedule` (`POST posts/{post}/reschedule`) backing the calendar's
  drag-and-drop move (see `calendar.md`).
- 2026-10-16: Alima rebrand of the post UI and the `client-review-requested` /
  `upcoming-post-reminder` emails (Deep Teal header with the hosted cream lock-up PNG, Poppins/Arial,
  Ink pill CTA, teal links, lilac studio note). No route, prop or behaviour changes.
- 2026-10-16: Media section reworked for large libraries — shows only attached assets plus Upload
  and Browse library tiles; browsing happens in a searchable, filterable, paginated dialog backed
  by the new `clients.assets.index` endpoint. `PostController::edit` no longer sends
  `availableAssets` (asserted missing in `PostEditorControllerTest`).
- 2026-10-16: Replaced the editor's native `window.confirm()` calls (unsaved-changes guard, AI
  caption replace) with the new app-wide `confirmDialog()` (`components/confirm-dialog.tsx`); the
  `beforeunload` prompt was dropped. Also fixed unreadable white text on destructive dropdown
  items (e.g. "Archive post") in `ui/dropdown-menu.tsx`.
- 2026-10-16: Redesigned the post editor (`posts/edit.tsx`) and its status workflow for clarity.
  The four tabs (Editor / Checklist & status / Comments / Activity) are gone: status now lives in a
  workflow panel at the top (stage track, guidance line, one recommended next action, secondary
  actions, a "More" menu for back/override/archive moves, and a confirm dialog with an optional
  activity-log note — the `comment` field `posts.transition` always accepted but the UI never
  sent). The editor became numbered composer panels with a channel-row schedule picker,
  thumbnail media grid with drag-drop upload, a live readiness checklist and preview in a sticky
  rail, a sticky save bar with unsaved-changes tracking/guard/Ctrl+S, and a Discussion panel with a
  comment thread and status timeline. The page was split into `resources/js/components/post-editor/*`
  plus `lib/post-workflow.ts` and `hooks/use-unsaved-changes-guard.ts`. Backend: `PostController`
  flashes success toasts on update/transition/comment, and `targetAccounts` now carries each
  platform's limits (fixes the caption counter showing no limit for channels added before saving).
  Tests: `test_editor_exposes_platform_limits_for_every_target_account`,
  `test_transition_flashes_a_toast_and_records_the_note`, and toast assertions on the update and
  comment tests.
- 2026-09-23: The Media section on `posts/edit.tsx` gained an inline "Upload asset" button (file
  input + `UploadCloud` button, gated on the new `can.create_asset` prop) so users can upload a new
  client asset without leaving the post editor to visit the client's Assets tab first.
  Implementation posts a plain `fetch()` (mirroring the `getXsrfTokenFromCookie()`/`X-XSRF-TOKEN`
  pattern already used by `clients/show.tsx`'s social-account connect flow) with
  `Accept: application/json` to the existing `clients.assets.store` route instead of an Inertia
  `<Form>` visit — a full Inertia redirect would have navigated away from the post editor and
  discarded any unsaved caption/hashtag/schedule changes. `AssetController::store` now checks
  `$request->wantsJson()` and, when true, returns the created asset (`id`/`url`/
  `original_filename`/`type`) as JSON instead of redirecting to `clients.show`; non-JSON callers
  (the client page's existing upload form) are unaffected. On success the new asset is merged into
  a local `assets` state (seeded from `availableAssets`) and auto-selected via
  `selectedAssetIds`/`asset_ids[]`, so it's immediately visible in the picker/live preview and gets
  attached on the next "Save changes". `PostController::edit` now also passes `can.create_asset`
  (`AssetPolicy::create` for the post's client). Covered by a new JSON-response test in
  `AssetControllerTest` and two `can.create_asset` assertions in `PostEditorControllerTest`.
- 2026-09-22: Added a fullscreen popup for the "Live preview" card on `posts/edit.tsx`: a `Maximize2`
  icon button next to the "Live preview" heading opens a shadcn `Dialog` (new `isPreviewFullscreen`
  state) that re-renders the same `SocialPostPreview` (plus the platform/handle pill switcher when
  multiple targets are selected) at a larger size (`sm:max-w-2xl`, `max-h-[90vh] overflow-y-auto` so
  tall mockups like the TikTok vertical card still fit/scroll on short viewports) so users can see
  the mockup without the sticky sidebar's width constraint. Purely presentational — no new state
  beyond the open/closed flag; reuses `activePreviewTarget`/`previewTargets`/`previewAssets` already
  derived for the sidebar preview. No backend changes.
- 2026-09-22: The TikTok live preview still looked too small/plain after the aspect-ratio fix, so
  `TikTokPreview` in `SocialPostPreview` was rebuilt to actually resemble the TikTok app: wider
  mockup (`max-w-[340px]` vs `260px`), a "Following / For You" top tab bar, a right-hand action
  rail with a circular avatar-initial badge plus like/comment/save/share icons each with a mock
  counter, and a music-disc badge — with the handle/caption/music line moved to the bottom-left
  to match the real layout instead of the previous cramped side-icon column.
- 2026-09-22: Fixed the TikTok live preview rendering poorly (collapsed/square instead of a tall
  9:16 phone mockup) caused by the invalid Tailwind class `aspect-9/16` in `SocialPostPreview`
  (must be `aspect-[9/16]`); also tightened the action-icon column and caption/music text spacing
  so they no longer overlap.
- 2026-09-22: Added left/right chevron-arrow buttons over the Instagram carousel media in
  `SocialPostPreview` (in addition to the existing dot indicators) so users can flick between
  multiple assets in the live preview, matching the request to make multi-asset navigation more
  discoverable than dots alone.
- 2026-09-22: Redesigned the "Editor" tab of `posts/edit.tsx` to add a Planable-style live visual
  preview alongside the existing form, so authors can see roughly how a post will render on the
  target platform instead of judging it from raw form fields alone. New presentational component
  `resources/js/components/social-post-preview.tsx` (`SocialPostPreview`) renders a small mockup
  card per platform — an Instagram feed post (avatar initial, square media, like/comment/share
  icons, caption+hashtags below), a TikTok-style vertical video card (full-bleed media, side action
  rail, caption overlay, music line), and a generic feed-style fallback for LinkedIn/Facebook —
  purely driven by the same state the form already tracks (caption, hashtags, selected assets,
  music/location name), no network round-trip or backend changes. The Editor tab is now a
  two-column layout on large screens (`lg:grid-cols-[minmax(0,1fr)_320px]`): the existing form/
  read-only content on the left, a new sticky "Live preview" card on the right with a pill switcher
  when multiple target accounts are selected (one mockup per platform/handle). Read-only viewers
  (`can.update: false`, e.g. `Role::ClientReviewer`) get the same preview panel driven by the saved
  `post` fields instead of live form state. No backend/data changes; all existing hidden form inputs
  and read-only fields are unchanged.
- 2026-09-20: The dashboard's new "Publish reliability" chart (`App\Actions\Posts\
  GetPublishReliabilityStatsAction`) reads `PostActivityLog::to_status` transitions to `Failed`/
  `Published` (scoped to `Post`s in the org) to show monthly failed/published counts and the
  average number of attempts it takes a post to reach `Published` — see `.junie/modules/home.md`
  for the dashboard-side write-up.
- 2026-09-20: Bugfix — re-queuing a `failed` post (`failed → scheduled`) left the previous attempt's
  `PostTarget::status`/`external_post_id`/`error` in place until `PublishPostJob` ran again, and the
  publish error was only ever shown next to the target/social account on `posts/edit.tsx`, not on the
  activity log. `TransitionPostStatusAction` now resets every target back to `pending` (clearing
  `external_post_id`/`error`) on any `failed → scheduled` move, and `App\Jobs\PublishPostJob` now
  passes a note summarizing each failed target's platform/handle/error as the transition `$comment`
  when moving a post to `failed`, so it lands on the `PostActivityLog` entry instead. Removed the
  per-target error text from the read-only target list in `posts/edit.tsx` (the status badge is
  kept). See `.junie/modules/publishing.md` for the full write-up.
- 2026-09-19: `CreatePostCommentAction` and `TransitionPostStatusAction` now dispatch database
  notifications (via the new `App\Services\PostNotificationService`) whenever a comment/decision
  crosses the client ⇄ org boundary, or a post lands in `waiting_client` — see
  `.junie/modules/notifications.md` for the full notification system this plugs into.
- 2026-09-19: Replaced the plain comma-separated hashtags text field on `posts/edit.tsx` with a new
  Instagram-style tag picker, `resources/js/components/hashtag-input.tsx` (`HashtagInput`): typing
  filters a suggestions dropdown, click/Enter/arrow-keys picks a suggestion, and Enter/comma on
  free text adds it as a chip; any leading `#` is stripped as it's typed. `PostController::edit` now
  computes and passes `hashtagSuggestions` (new private `hashtagSuggestions()` helper) — the
  client's `BrandBrain::hashtag_policy.always_use` list plus every hashtag already used on the
  client's other posts. Also fixes the root inconsistency this was meant to solve: some users typed
  `#xyz`, others `xyz`. `Post::booted()` now normalizes `hashtags` on every save (strip leading `#`,
  trim, dedupe, drop empties) as a server-side backstop regardless of entry point. This changes the
  stored/returned format from (inconsistently) `#`-prefixed to always bare — `review/show.tsx`
  already added its own `#` prefix when rendering, and `UploadPostAdapter` now sends bare tags to
  the vendor API instead of whatever was typed. Updated existing tests
  (`PostEditorControllerTest`, `UploadPostAdapterTest`) that asserted the old `#`-prefixed value, and
  added `tests/Unit/Models/PostTest.php` for the normalization itself.
- 2026-09-19: The Media section on `posts/edit.tsx` now shows actual image/video previews of the
  selected assets instead of just filename badges/pills. New shared
  `resources/js/components/asset-preview-carousel.tsx` (`AssetPreviewCarousel`) renders a single
  large preview (image `<img>` or `<video>`) with prev/next arrow buttons and a clickable thumbnail
  strip when more than one asset is selected, an index counter ("2 / 4"), and a filename caption;
  falls back to a placeholder icon for assets with no `url`. Used in both the read-only Editor view
  (`post.assets`) and the editable form (a new `selectedAssets` derived list — `availableAssets`
  filtered by `selectedAssetIds` — kept alongside the existing asset-toggle pill picker). No backend
  changes; relies on the `url`/`type` fields already returned per asset. No new dependency was added
  (a lightweight custom carousel, not shadcn's Carousel/embla, since that package isn't installed).
- 2026-09-19 (Step 1.4): The `scheduled` transition now actually kicks off publishing.
  `TransitionPostStatusAction` enqueues the new `App\Jobs\PublishPostJob` (delayed until the
  earliest target's `scheduled_at_utc`) whenever `$to === PostStatus::Scheduled`; nothing calls
  the vendor before that (the §6 checklist re-check from Step 0.10 already guards the `scheduled`
  destination). The job moves the post to `publishing`, calls `PublishAdapter::publish()`, then
  writes each target's `TargetResult` onto its `PostTarget` (new `status`/`external_post_id`/
  `error` columns) before moving the post to `published`/`failed`. No new `/posts/:id/schedule`
  route was added — see `.junie/modules/publishing.md`'s Known Gaps for why the existing
  `posts.transition` endpoint was reused instead. `PostController`'s editor payload and the
  read-only target list on `posts/edit.tsx` now surface each target's publish status/error.
- 2026-09-18: Refined the Comments and Activity log card UI on `posts/edit.tsx` (`CommentEntry`/
  `ActivityLogEntry`) — the previous compact rows (`p-2.5`, tight `gap-2`) felt squished, especially
  once expanded. Each entry now uses a `rounded-lg`/`bg-card` container (was `rounded-md`/
  `bg-muted/20`) with a small circular icon avatar, a two-line header (name/badges/timestamp on one
  row, the truncated preview on its own row below, hidden once expanded instead of `hidden sm:inline`
  so it no longer competes for horizontal space on narrow screens), and a taller chevron-affordance
  button (`p-3.5`). The expanded `CollapsibleContent` now gets its own `bg-muted/10` panel with more
  vertical padding (`py-3`) and is indented to align under the avatar (`pl-[3.25rem]`) instead of the
  cramped `px-2.5 py-2`, and note text uses `leading-relaxed` for readability. Activity log entries
  additionally group the from/to status badges together so they wrap as a unit on narrow viewports,
  and the row-level button is disabled (no hover/chevron) for the rare entry with no expandable
  details. List spacing between entries increased from `gap-2` to `gap-3` in both tabs. No backend/
  data changes.
- 2026-09-18: Client-portal audit fix: `posts/edit.tsx`'s "Editor" tab previously rendered nothing
  at all for any user without `can.update` (`Role::ClientReviewer`, `Role::Viewer`) — the backend
  (`PostController::edit`/`transformPostForEditor`) already returned the full post payload
  regardless, so this was a frontend-only gap. It now renders a read-only view (caption, hashtags,
  target accounts with their scheduled date/time, music/location, media) when `can.update` is
  false. Added a regression test in `tests/Feature/PostEditorControllerTest.php` asserting a
  `Role::ClientReviewer` still receives the full `post` payload with `can.update: false`.
- 2026-09-16: Restyled `resources/views/emails/client-review-requested.blade.php` and
  `resources/views/emails/upcoming-post-reminder.blade.php` to match the current brand guidelines
  (`.junie/brand-guidelines.md`) instead of the old purple/blue gradient look: dark warm-black header
  bar with the italic serif "graphy." wordmark, `Playfair Display`/Georgia serif page heading, cream/
  off-white body background, flat white cards with warm-neutral borders, and the signature orange
  (`primary`) CTA button/link color — all inlined as hex approximations of the `oklch` theme tokens
  since email clients don't support CSS variables/Tailwind. Also fixed an unrelated stray broken line
  (leftover incomplete `$users = User::where(...)` statement) in
  `SendUpcomingPostReminderEmailsAction` that was causing a `ParseError`.
- 2026-09-16: Added the post-reminder-email feature: `posts:send-upcoming-reminders {--days=3}`
  artisan command (scheduled daily at 08:00 in `routes/console.php`) runs the new
  `App\Actions\Posts\SendUpcomingPostReminderEmailsAction`, which finds `Scheduled` posts' targets
  due to publish within the window and not yet reminded about, and emails every user of the owning
  organization one `App\Mail\UpcomingPostReminderMail` digest (`resources/views/emails/upcoming-
  post-reminder.blade.php`). New nullable `reminder_sent_at` timestamp on `post_targets` (migration
  `2026_09_16_150000_add_reminder_sent_at_to_post_targets_table`) prevents double-sending on re-run.
- 2026-09-16: The signed `/review/{token}` link emailed on a `waiting_client` transition is now
  recorded on the resulting `PostActivityLog::review_url` (new nullable `text` column, migration
  `2026_09_16_140000_add_review_url_to_post_activity_logs_table`) — `SendClientReviewRequestedEmailAction`
  now returns the URL (or `null` if skipped) instead of `void`. Added `ResendClientReviewEmailAction`
  + `PostPolicy::resendReviewEmail` + `PostController::resendReviewEmail` (route
  `posts.activity-logs.resend-review-email`) so an org user can re-send the email straight from the
  activity log without issuing a new `ReviewToken`. On `posts/edit.tsx`, `ActivityLogEntry` now shows
  the stored review link (with a "Resend email" button for authorized users) and applies
  `break-words`/`break-all` to the expanded note/link content, fixing an overflow bug where a long
  note (or an unbroken URL) could spill outside the card.
- 2026-09-16: Redesigned `resources/js/pages/posts/edit.tsx`'s navigation: the previously long,
  stacked list of sections (editor form, checklist, move status, comments, activity log) is now a
  `Tabs`/`TabsList`/`TabsContent` layout (shadcn `components/ui/tabs`) with four tabs — "Editor",
  "Checklist & status" (checklist + move-status transitions together), "Comments" (tab label shows a
  count badge), and "Activity log" — so users can jump directly to the part of the page they need
  instead of scrolling past everything. Section contents/forms are unchanged. The client show page's
  "Posts" section (see `clients.md`) got the same Tabs treatment.
- 2026-09-16: Added `Database\Seeders\DemoContentSeeder` (seeds posts/targets/comments/activity logs
  + social accounts + campaigns + assets for the `ClientSeeder` demo clients) and wired it into
  `DatabaseSeeder` after `InvoiceSeeder`.
- 2026-09-09: Added a nullable `review_message` text field on `Post` — a note for the client, edited
  via a new textarea on `posts/edit.tsx` (submitted through the existing `PostController::update`/
  `UpdatePostAction`), shown to the client in `ClientReviewRequestedMail`'s email body and on the
  review portal (`review/show.tsx`). `ReviewController::show` also now exposes `post.comments`
  (the post's `PostComment`s filtered to non-`internal_only`) so the client can see any non-internal
  comments left on the post. New migration `2026_09_09_120000_add_review_message_to_posts_table`.
- 2026-09-09: Applied the same foldable-card treatment to the Comments section on `posts/edit.tsx`:
  each comment is now a `Collapsible` entry (new `CommentEntry` component) with a user icon, internal
  badge, and truncated preview that expands to the full comment body on click — fixes long comment
  bodies getting cut off, matching the Activity log restyle below.
- 2026-09-09: Restyled the Activity log section on `posts/edit.tsx`: each entry is now a foldable
  `Collapsible` card (via `resources/js/components/ui/collapsible.tsx`) with a user icon, from/to
  status badges, and a note preview that expands to show the full note text on click, instead of a
  single truncatable line — fixes long `changes_requested` notes getting cut off.
- 2026-09-09: Fixed a bug where a post in `changes_requested` had no way back into review — the
  `PostStatusTransitionMap` entry for `changes_requested` was missing `internal_review` (only allowed
  `draft`/`archived`), so the "Move status" section on `posts/edit.tsx` never rendered a button to
  send it back to Internal review. Added `PostStatus::InternalReview` to that map entry (no policy
  change needed, `PostPolicy::transition` already allows any legal move for internal roles) and a
  regression test in `PostStatusTransitionMapTest`.
- 2026-09-09: Restyled the `posts/edit.tsx` header with a gradient-brand hero banner, matching the
  agency-premium restyle applied to the dashboard, calendar, and clients pages.
- 2026-09-09 (Step 0.10): Added the full editor UI. New `App\Actions\Posts\EvaluatePostChecklistAction`
  (interim §6 checklist: caption/targets/schedule/media, with a text-only-capable-platform exemption
  from media via new `Platform::isTextOnlyCapable()`/`Platform::captionLimit()`), `UpdatePostAction`
  + `UpdatePostRequest`, `PostController::edit`/`update` (routes `posts.edit`/`posts.update`), the
  `post_assets` pivot (`Post::assets()`/`Asset::posts()`), and `resources/js/pages/posts/edit.tsx`
  (caption/hashtags/music/location, independent per-target schedule rows with char counts, asset
  picker, checklist panel, transition buttons, comments, activity log). `TransitionPostStatusAction`
  now refreshes `checklist_snapshot` on every transition, and `TransitionPostRequest` re-validates the
  checklist server-side before any move to `scheduled` (Phase 0 exit criterion: "Schedule blocked
  without media unless platform is text-only"). The client page's post cards now link to the editor.
- 2026-09-08: Initial implementation of `Post`/`PostTarget` models, migrations, `PostPolicy`,
  `CreatePostAction`, `StorePostRequest`, `PostController::store`, and the London/Dubai independent-
  timezone PHPUnit test (Step 0.8).
- 2026-09-08: Exposed the Posts module in the frontend — `ClientController::show` now returns
  `posts`/`targetAccounts`/`can.createPost`, and `clients/show.tsx` gained a collapsible "Posts"
  section with a create form and post list, so posts are reachable from the client detail page.
- 2026-09-09: The "Posts" collapsible section on `clients/show.tsx` now defaults to folded/closed on
  page load (`postsOpen` initial state changed from `true` to `false`), matching the other collapsible
  sections (Social accounts, Campaigns, Assets, Billing history).
- 2026-09-09 (Step 0.9): Added the `Post::status` transition state machine
  (`App\Actions\Posts\PostStatusTransitionMap`), `TransitionPostStatusAction`,
  `PostPolicy::transition`/`comment`, `TransitionPostRequest`/`StorePostCommentRequest`,
  `PostController::transition`/`comment` (routes `posts.transition`/`posts.comments.store`), and the
  new `PostActivityLog`/`PostApproval`/`PostComment`/`ReviewToken` models + migrations +
  `CreatePostCommentAction`/`CreateReviewTokenAction`. No frontend UI for any of this yet (that's Step
  0.10). See Known Gaps above for the `approval_mode` follow-up.
- 2026-09-09 (Step 0.13): Added the unauthenticated client review portal. `TransitionPostStatusAction`
  now accepts a nullable `$user` and, on any transition to `waiting_client`, calls the new
  `App\Actions\Posts\SendClientReviewRequestedEmailAction` (issues a `ReviewToken` via
  `CreateReviewTokenAction` and emails `App\Mail\ClientReviewRequestedMail` to
  `Client::approval_email` via Resend, skipping with a warning log if unset — requires the
  `resend/resend-php` composer package, which backs Laravel's built-in `resend` mail transport and
  must be installed or sending throws `Class "Resend" not found`). New `ReviewController`
  (`show`/`decide`, routes `review.show`/`review.decide` at `/review/{token}`, deliberately outside
  the `auth`/`verified` middleware group) resolves and validates the token itself (hash lookup +
  `ReviewToken::isValid()` + client/post scoping) rather than using `PostPolicy`, and renders the new
  `resources/js/pages/review/show.tsx` light-themed page (Approve / Request changes only, no money
  fields) per the brand guidelines §7. New `ReviewDecisionRequest` validates the decide body.
- 2026-10-17: Phase 16 (Step 16.10): `AttachGeneratedImagesToPostAction` attaches AI Image Studio variants to `post_assets` in one transaction, marks them selected, re-runs `EvaluatePostChecklistAction` and logs a `PostActivityLog` note ("Added 2 AI images") without changing status. `POST ai-image-generations/{generation}/use` creates the idea's draft first when needed. `Post::hasAiGeneratedMedia()` feeds AI disclosure on publish. The editor shows the studio below the form (`imageStudio` prop) and an "AI" tag on AI media. ClientReviewers only ever see attached assets (portal regression in `ImageStudioHousekeepingTest`).
- 2026-10-20: A post is sent to Upload-Post as soon as it's `scheduled` and stays `scheduled` while
  the vendor holds it (`Post::isHeldByVendor()`). Leaving `scheduled` (`PostStatusTransitionMap::
  leavesSchedule()`), moving, editing or deleting such a post cancels the held channels first. See
  `publishing.md` ("Hand-off at scheduling time").
- 2026-10-20: Step M-1.6: editor split into feature components (≤ 300 lines each, CI-guarded), shared `DateTimePicker` with viewer-time hint and explicit "Same moment", keyboard shortcuts + `?` dialog, Undo toasts.
