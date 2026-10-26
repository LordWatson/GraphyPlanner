# Publishing

## Purpose
Pluggable publish-vendor integration per spec §7. Step 1.1 defined the `PublishAdapter` contract;
Step 1.2 implements `publish()`'s per-platform field mapping against Upload-Post's API; Step 1.3
wires up the account connect flow (`SocialAccountController::connect`/`callback`); Step 1.4 wires
`publish()` up to a queued job triggered by the existing `scheduled` transition; Step 1.5 adds a
signature-verified webhook plus a poll fallback that sync a vendor's final publish outcome back
onto `PostTarget`/`Post`; Step 1.6 wires up health-check-driven token-expiry detection; Step 1.7
adds a per-platform rollout gate (Instagram feed → Reels → TikTok → Facebook → LinkedIn) that
`publish()` consults before ever calling the vendor.

## Models / Tables
- None new. `SocialAccount` (see `social-accounts.md`) has the `provider`, `externalProfileId`,
  `externalAccountId`, `connectedAt` columns; `externalProfileId`/`connectedAt` are now populated by
  the Step 1.3 connect callback (`provider`/`externalAccountId` remain unused until later steps).
  `Organization::upload_post_key` (Step 0.15) is the per-org Upload-Post API key used to authenticate
  every adapter request.

## Actions / Services / Contracts
- `App\Contracts\PublishAdapter`: the adapter contract (spec §7) — `connectAccount(SocialAccount): string`,
  `handleCallback(array $query): void`, `publish(Post, Collection<SocialAccount>): Collection<TargetResult>`,
  `cancel(string $externalPostId): void`, `health(SocialAccount): bool`, and (Step 1.5)
  `checkStatus(SocialAccount, string $externalPostId): ?TargetResult` — polls the vendor for the
  final outcome of a previously accepted publish, returning `null` when it's still unresolved
  rather than treating "don't know yet" as a failure. Any future vendor (Upload-Post in Step 1.2,
  a second adapter in Phase 4) implements this same interface so callers never change.
- `App\Support\Publishing\TargetResult`: readonly DTO mirroring spec §7's `TargetResult` shape
  (`accountId`, `ok`, `externalPostId`, `error`, `sentFields[]`, `skippedFields[]`), returned per
  target from `publish()`.
- `App\Services\Publishing\NullPublishAdapter`: no-op `PublishAdapter` implementation — never connects,
  always returns a failed `TargetResult` per target from `publish()`, and reports `health()` as `false`.
  No longer the default binding (superseded by `UploadPostAdapter` in Step 1.2), but kept and tested as
  a safe fallback implementation for future use (e.g. a maintenance-mode binding).
- `App\Services\Publishing\UploadPostAdapter` (Step 1.2; endpoint fixed 2026-09-20): the Upload-Post
  `PublishAdapter` implementation and the default binding in `AppServiceProvider::register()`.
  `publish()` posts to a media-appropriate endpoint — `POST {base_url}/upload` (post has a video asset),
  `POST {base_url}/upload_photos` (image asset(s), no video), or `POST {base_url}/upload_text` (no
  assets) — resolved by `resolveUploadEndpoint()`, via `Illuminate\Support\Facades\Http`, authenticated
  with the target's `SocialAccount->organization->upload_post_key` (sent as `Authorization: Apikey
  <key>` — Upload-Post's API uses a custom `Apikey` auth scheme, not OAuth `Bearer`), and maps:
  - Common fields (all platforms): `user` (`external_account_id`/`external_profile_id`/account id
    fallback), `title` (`Post::master_caption`), `platform` (single-element array), `scheduled_date`
    (the matching `PostTarget::scheduled_at_utc`, ISO-8601), `video` (first attached video `Asset::url`,
    only sent to the `/upload` endpoint) or `photos` (all attached image `Asset::url`s, only sent to
    the `/upload_photos` endpoint), `hashtags` (`Post::hashtags`) — each skipped (and recorded in
    `TargetResult::skippedFields`) when the source data is empty.
  - Image format (2026-10-19): before attaching local images, `attachLocalMediaFiles()` asks
    `App\Services\Media\PublishImageCropper` for the channel's target ratio
    (`targetRatio()` — the post's fixed `MediaRatio`, or for an Instagram `original` the first
    image's shape clamped to 4:5–1.91:1) and crops each image around its `post_assets` focus point
    + zoom (`crop()`, GD via Intervention, same format re-encoded at quality 92). Never fails a
    publish: an unreadable image, an animated GIF, or an image already in shape goes out as the
    original. Skipped for videos, remote (URL-only) images (logged), and AI-generated images sent to
    LinkedIn (re-encoding would strip the C2PA credentials LinkedIn reads).
  - Instagram: `location_id` (`Post::location['id']`), `media_type` (`REELS` for a first attached video
    asset, `IMAGE` for image — `STORIES` isn't derivable from any existing field, always skipped), and
    `audio_id`/`audio_name` (`Post::music['id']`, falling back to `Post::music['name']` only when no
    real id is set, per spec §7.1).
  - TikTok: `tiktok_music_id` (`Post::music['id']`), `location` (`Post::location['name']`).
  - Every platform (Step 16.13 AI disclosure): `is_ai_generated=true` whenever
    `Post::hasAiGeneratedMedia()` (any attached asset has `assets.is_ai_generated`, i.e. came out
    of the AI Image Studio); otherwise recorded as skipped. Upload-Post maps this alias to each
    platform's native label (https://docs.upload-post.com/guides/ai-content-labeling):
    | Platform | Native label | Support |
    |---|---|---|
    | TikTok | `is_aigc` | Yes (Direct Post) |
    | Instagram | `is_ai_generated` | Yes (Reels, photos, carousels) |
    | Facebook | `facebook_is_ai_generated` | Reels only; other Page posts have no API label |
    | LinkedIn | none | Reads C2PA Content Credentials embedded in the media (we keep the Gemini original unre-encoded for this) |
  - LinkedIn/Facebook: only the common fields (caption + media + schedule), per spec §7.1.
  - Returns one `TargetResult` per target: `ok: true` with `externalPostId` from the vendor's
    `request_id`/`id` on a successful response; `ok: false` with the vendor's `error` (or raw body) on
    a non-2xx response or thrown exception. Every publish attempt and rejection is logged
    (`Log::info`/`warning`/`error`) with `post_id`/`social_account_id`/`platform` context.
  - Step 1.7: before any of the above, `publishToTarget()` resolves the target's
    `PlatformRolloutStage` via `PlatformRolloutGate::stageFor()` and checks `stageEnabled()`. A
    disabled stage short-circuits — no HTTP request is made at all — and returns a failed
    `TargetResult` (`"Publishing to {stage label} is not yet enabled (Step 1.7 rollout)."`),
    logged via `Log::info`.
  - `connectAccount()` (Step 1.3, bugfix): first calls `POST {base_url}/uploadposts/users` with the
    same `username` to create the Upload-Post profile if it doesn't exist yet — Upload-Post's
    `generate-jwt` endpoint only issues a linking token for a profile that already exists, it does
    NOT create one implicitly, so skipping this step causes `generate-jwt`/`schedule` to fail with a
    `PROFILE_NOT_FOUND` error. A response indicating the profile already exists (`error_code`/`error`
    containing "exist") is treated as success (idempotent); any other non-2xx response throws
    `RuntimeException`. Then calls `POST {base_url}/uploadposts/users/generate-jwt` with `username`
    (`external_account_id`/`external_profile_id`/account id fallback) and `redirect_url` (the app's
    `social-accounts.callback` route, carrying `social_account_id` in the query string so the
    callback can identify the account). Returns the vendor's `access_url`/`url`; throws
    `RuntimeException` on a non-2xx response or request failure (logged via `Log::error`/`warning`).
  - `handleCallback()` (Step 1.3): reads `social_account_id` from the callback query, looks up the
    `SocialAccount`, and persists `external_profile_id` (from the query's `profile`/`username`,
    falling back to the existing value) plus `connection_status = connected` and `connected_at =
    now()`. Logs a warning and no-ops if `social_account_id` is missing or doesn't match an account.
  - `cancel()` remains interim/partial — real cancellation isn't wired up to any caller yet.
  - `checkStatus()` (Step 1.5, confirmed against https://docs.upload-post.com/api/upload-status):
    `GET {base_url}/uploadposts/status?request_id={externalPostId}&job_id={externalPostId}` (both
    query params are sent since `$externalPostId` may be either kind of id — `request_id` from an
    `async_upload` publish, `job_id` from a scheduled one — and the caller doesn't track which).
    Top-level `status` of `completed`/`failed` is resolved; anything else (`pending`/`queued`/
    `processing`/`in_progress`/`not_found`, an unrecognized shape, non-2xx, or a thrown exception)
    returns `null` so `PollPendingPublishStatusesAction` retries later instead of wrongly marking
    the target failed. Because a single job can fan out to multiple platforms, the response's
    per-platform `results[]` is checked first for an entry matching the account's `platform` (its
    own `success`/`message` wins); only when no such entry exists does the top-level `status`/
    `message` decide the outcome.
- `App\Actions\Publishing\SyncPublishStatusAction` (Step 1.5): the single seam both the webhook and
  the poll fallback use to record a vendor's final outcome for a `PostTarget` still `pending`.
  Marks the target `published`/`failed`, then — only if the post is still `publishing` and every
  other target on it has also resolved (no more `pending` targets) — transitions the post itself to
  `published`/`failed` via `TransitionPostStatusAction`, keeping the activity log complete. A target
  whose post already moved on (e.g. reverted, or a different sync path already finalized it) is
  still updated but doesn't re-trigger a transition.
- `App\Actions\Publishing\PollPendingPublishStatusesAction` (Step 1.5): the webhook fallback. Finds
  every `PostTarget` that's `pending`, has an `external_post_id`, belongs to a `publishing` post,
  and hasn't been updated in at least `$olderThanMinutes` (default 5) minutes; calls
  `PublishAdapter::checkStatus()` for each and, when resolved, finalizes it via
  `SyncPublishStatusAction`. Run via `PollPendingPublishStatusesCommand`
  (`publishing:poll-pending-statuses {--minutes=5}`), scheduled every 5 minutes in
  `routes/console.php`.
- `config('services.upload_post.base_url')` (env `UPLOAD_POST_BASE_URL`, defaults to Upload-Post's
  real API base URL): the only adapter-wide config left. Both the publish API key
  (`organizations.upload_post_key`) and the webhook signing secret
  (`organizations.upload_post_webhook_secret`, Step 1.5, confirmed against
  https://docs.upload-post.com/api/webhooks — Upload-Post issues one secret per account, not a
  single app-wide one) live per-organization, encrypted at rest alongside each other.
- `App\Enums\PlatformRolloutStage` (Step 1.7): the plan's five rollout milestones — Instagram
  feed, Instagram Reels, TikTok, Facebook, LinkedIn (`order()` returns them in that literal
  order). Instagram feed/Reels are deliberately *not* separate `Platform` enum values (both are
  the `instagram` platform, distinguished only by the post's derived media type), so this enum
  exists specifically to give each of the five milestones its own togglable stage.
- `config('publishing.rollout')` (Step 1.7): one boolean flag per `PlatformRolloutStage`, each
  overridable via its own env var (`PUBLISH_ROLLOUT_INSTAGRAM_FEED`/`_INSTAGRAM_REELS`/`_TIKTOK`/
  `_FACEBOOK`/`_LINKEDIN`). All five default to `true` — every stage already has a passing
  publish test against faked sandbox-shaped data from Steps 1.2-1.6 — but the flags let any
  single stage be disabled independently (e.g. during a future vendor-side regression) without
  affecting the others.
- `App\Services\Publishing\PlatformRolloutGate` (Step 1.7): `stageFor(Post, SocialAccount)`
  resolves which of the five stages a target belongs to (Instagram feed vs. Reels inferred from
  the post's first attached asset type — the same inference `UploadPostAdapter::applyInstagramFields()`
  already uses for `media_type`); `stageEnabled(PlatformRolloutStage)`/`isEnabled(Post,
  SocialAccount)` read `config('publishing.rollout.*')` (missing key defaults to disabled).
  `UploadPostAdapter` depends on this via constructor injection (default-constructed when not
  explicitly passed, so every existing `new UploadPostAdapter` call site — tests included — keeps
  working unchanged).
- `App\Contracts\MusicProvider` (Step 1.8.1): `search(string $query, Platform $platform):
  Collection<MusicTrack>` and `find(string $id, Platform $platform): ?MusicTrack`, mirroring the
  `PublishAdapter` pattern (interface first, no default binding registered yet — that lands with
  Step 1.8.2's `UploadPostMusicProvider`) so the vendor is swappable/mockable. Exists to let
  editors search/browse a real sound catalog and land a track's vendor id in `Post::music`
  unchanged, ready for `UploadPostAdapter`'s existing `audio_id`/`audio_name`/`tiktok_music_id`
  mapping (Step 1.2) to carry it through.
- `App\Support\Publishing\MusicTrack` (Step 1.8.1): readonly DTO (`id`, `name`, `artist`,
  `previewUrl`, `platform`) returned by `MusicProvider`, mirroring `TargetResult`'s style.
- `App\Services\Publishing\UploadPostMusicProvider` (Step 1.8.2): the default `MusicProvider`
  binding. Confirmed against Upload-Post's real API reference
  (https://docs.upload-post.com/api/search-tiktok-music,
  https://docs.upload-post.com/api/get-tiktok-music), the vendor only exposes a music/sound
  catalog for **TikTok** (`GET /uploadposts/tiktok/music/search`, scoped to a connected TikTok
  profile's cached commercial-music charts) — there is no Instagram audio-search endpoint
  anywhere in Upload-Post's API (Instagram's own audio search is a separate Meta Graph API
  endpoint Upload-Post doesn't proxy), so `search()`/`find()` always return an
  empty-collection/`null` for every non-TikTok platform without calling the vendor. Since
  `MusicProvider::search()`/`find()` (Step 1.8.1) carry no `SocialAccount`/organization
  parameter, the org is resolved from the authenticated user the same way every controller in
  this app does (`$request->user()->organization`, see `.junie/modules/settings.md`), and the
  vendor's required `profile` param from that org's first *connected* TikTok `SocialAccount`; no
  authenticated org or no connected TikTok account both short-circuit to an
  empty-collection/`null` result (logged) rather than an exception. `find()` has no matching
  "get track by id" vendor endpoint, so it re-uses the same search endpoint (unfiltered query,
  i.e. the trending chart) and matches the vendor's own `id` locally — see Known Gaps.
- `App\Actions\Publishing\SearchMusicAction` (Step 1.8.3): the thin Action a controller will call
  with `(string $query, Platform $platform)`, constructor-injected with `App\Contracts\MusicProvider`
  (never the concrete `UploadPostMusicProvider`) and delegating entirely to `MusicProvider::search()`.
  A single read call, so no `DB::transaction()`; logs the search start and result count (platform +
  count only, never the raw query text) the same way other publishing Actions/Services log. Wired up
  to `PostController::musicSearch` in Step 1.8.4.
- `config('services.facebook')` (Step 1.9.1): `client_id`/`client_secret`/`redirect_uri` (env
  `FACEBOOK_CLIENT_ID`/`FACEBOOK_CLIENT_SECRET`/`FACEBOOK_REDIRECT_URI`, all `null` until a real
  Facebook App — Facebook Login for Business, `instagram_basic` + `instagram_content_publish` — is
  registered) and `graph_base_url`/`graph_version` (default `https://graph.facebook.com`/`v20.0`).
  A separate Facebook App from Upload-Post; the client secret is never logged.
  `FacebookInstagramConnectController::graphUrl()` (bugfix, 2026-10-12) validates that the
  configured `graph_base_url`'s host ends with `facebook.com` before issuing any Graph API call —
  production had `FACEBOOK_GRAPH_BASE_URL` typo'd as `https://graph.facebook.co` (missing the
  trailing `m`), which previously surfaced only as an opaque `cURL error 60: SSL: no alternative
  certificate subject name matches target host name` deep inside Guzzle. A mismatched host now
  throws a `RuntimeException` with the bad URL in the message, caught by the existing
  `handleCallback()` try/catch and surfaced as a normal flash error instead of a 500. Covered by
  `FacebookInstagramConnectControllerTest::test_callback_fails_gracefully_when_graph_base_url_is_misconfigured`.
- `App\Services\Publishing\MetaGraphMusicProvider` (Step 1.9.4): the default `MusicProvider`
  binding since Step 1.9, replacing `UploadPostMusicProvider` at that seam (Upload-Post has no
  Instagram sound-library endpoint, per Step 1.8.2). Calls Meta's real
  `GET /{version}/ig_audio` (https://developers.facebook.com/documentation/instagram-platform/
  content-publishing/audio-api) directly against `graph.facebook.com` for `Platform::Instagram`
  only, resolving the connected Instagram `SocialAccount`'s `meta_access_token`/
  `meta_instagram_user_id` (Step 1.9.2) from the authenticated user's organization (same
  resolution pattern as `UploadPostMusicProvider::connectedTikTokAccount()`); every other platform
  (TikTok) is delegated unchanged, via constructor-injected composition, to
  `UploadPostMusicProvider` (still bound directly for its own tests/consumers, just no longer the
  default `MusicProvider`). Throws `MusicProviderUnavailableException` with `errorCode()` set to
  `meta_not_connected` (Step 1.9.5) when the org has no Instagram account with a Meta grant yet, so
  the editor can show an actionable connect prompt instead of a bare error. `find()` calls
  `GET /{version}/{ig-audio-id}` (Get Audio Metadata), returning `null` on a 404. Maps `audio_id` →
  `MusicTrack::id`, `title` → `name`, `display_artist` → `artist`,
  `download_url`/`on_platform_audio_preview_link` → `previewUrl`. The access token is never logged.

### Publish pipeline (Steps I-1.2 / I-1.3 / I-1.4)
- **Dispatch id (I-1.2).** `posts.publish_dispatch_id` (uuid). `TransitionPostStatusAction` writes
  a fresh one on every move into `scheduled` and clears it on the way out; `PublishPostJob`
  carries the id it was dispatched with and no-ops (logged `info`) when it no longer matches, so
  a stale delayed job can never fire early. `ReschedulePostAction::redispatchIfScheduled()` (also
  used by `UpdatePostAction` when targets change) mints a new id inside the same transaction and
  dispatches a new job for the new times after commit — scheduled posts can now be dragged on the
  calendar.
- **Decision: keep delayed local jobs for now (I-1.2).** The plan recommends handing a post to
  Upload-Post as soon as it's scheduled. That needs a working `PublishAdapter::cancel()` (and an
  update/re-sync call) so an edit or reschedule can pull the vendor-held job back; `cancel()` is
  still a stub with no org context (see Known Gaps). Until I-3.6 lands, early hand-off would leave
  orphaned vendor jobs on every reschedule, so jobs stay local and delayed. Revisit with I-3.6.
- **`PublishPostJob` (orchestrator).** `ShouldBeUnique` keyed `post_id:dispatch_id`, `$tries = 3`,
  `$maxExceptions = 2`, `$timeout = 60`, backoff `[30, 120, 600]`. Locks the post row
  (`lockForUpdate`) while moving `scheduled → publishing`, gives every not-yet-published target a
  fresh `post_targets.publish_attempt_id`, and fans out one `PublishPostTargetJob` per target in a
  `Bus::batch()` (`allowFailures`). `failed()` fails the unsent targets and resolves the post, so a
  crash never leaves it in `publishing`. Suspended orgs still skip (post stays `scheduled`).
- **`App\Jobs\PublishPostTargetJob` (one target).** `Batchable`, same retry settings,
  `$timeout = 180`. At-most-once per attempt: locks the target, requires its own
  `publish_attempt_id`, skips a target already sent, and stamps `publish_requested_at` right before
  the vendor call. A re-run that finds the stamp without an `external_post_id` fails the target
  ("interrupted mid-request — check the channel before retrying") instead of risking a double post.
  Acceptance leaves the target `pending` + `external_post_id`; rejection fails it. `failed()` fails
  the target and resolves the post.
- **`App\Actions\Publishing\ResolvePostPublishOutcomeAction`.** The one place a `publishing` post
  is finalized (called by the target job, `SyncPublishStatusAction` and the timeout). Locks the
  post; waits while any target is `pending`; then all published → `published`, none → `failed`, a
  mix → **`partially_published`** (new `PostStatus`). Failures go on the activity-log note, the org
  gets `PostPublishFailed` (after commit; wording adapts to partial).
- **`App\Actions\Publishing\RetryFailedPostTargetsAction`** (`POST posts/{post}/retry-failed-targets`,
  `PostPolicy::retryFailedTargets` — Owner/Strategist/Designer on a `failed`/`partially_published`
  post). Resets only the `failed` targets with fresh attempt ids, moves the post to `publishing`
  (note lists the channels) and batches their jobs after commit. Published targets are untouched.
  `failed/partially_published → publishing` exists in the map for this action only —
  `PostPolicy::transition` refuses it as a manual move (`PostStatusTransitionMap::isRetryMove()`).
- **Vendor HTTP (`App\Support\Publishing\VendorHttp`, `config('publishing.http')`).** Every
  Upload-Post and Meta Graph call gets `timeout` (30s), `connectTimeout` (10s) and jittered
  exponential retries (`retries` = 2, `retry_base_ms` = 500). Reads (status, health, connect,
  music search) retry on 429/5xx/connection errors; the publish POST retries **only on 429** (a
  5xx or timeout may already have posted) and sends the target's attempt id as `Idempotency-Key`.
  `UploadPostAdapter` only sends `scheduled_date` when it's more than a minute in the future, so a
  retried or past-due target publishes immediately instead of being rejected.
- **Stuck targets.** `PollPendingPublishStatusesAction` fails any target still `pending` on a
  `publishing` post past `publishing.delivery_timeout_hours` (24) — "No delivery confirmation after
  N hours." — which resolves the post (Home shows it as failed / partly published). Walks rows with
  `lazyById()`.
- **After-commit side effects (I-1.4).** `TransitionPostStatusAction` re-checks the move against the
  **locked** row (`InvalidPostTransitionException` → 409 JSON / redirect-back with a `to` error and
  toast) and runs the publish-job dispatch, notifications and the client email in
  `DB::afterCommit()`. Every mailable and notification is `ShouldQueue`, and every queue connection
  has `after_commit => true`.

## Phase M-2 — Publishing parity (Steps M-2.1 to M-2.9, `mvp.md`)

### Capability registry (M-2.1 / I-3.3)
- `config/platforms.php` is the single source of truth for every platform rule: caption/title
  limits, hashtag policy (`hashtags`, `max_hashtags`, `hashtag_spacer`), link policy
  (`links_in_caption`, `link_field`), first comment / alt text support, text-only, per-account
  `settings` (keyed by the vendor field they're sent as, e.g. `pinterest_board_id`), the image
  framings (`media_ratios`), the Image Studio default (`ai_image_ratio`), caption fold, vendor field
  names (`vendor`, `vendor_prefix`, `vendor_description`, `cover_field`, `thumb_offset_field`),
  `keep_ai_originals`, `hosted_connect`, and the **formats** each target can take (feed, carousel,
  reel, story, video, photo, post, short, pin, update) with their media rules (kinds, item bounds,
  aspect range, `crop_to`, `fill_screen`, `vertical`, video length, file size) and vendor media type.
- `App\Services\Publishing\PlatformCapabilities` (deliberately not a singleton, so runtime config
  changes apply) → `for(Platform): PlatformSpec`, `resolveFormat()` (explicit, else the first
  non-forced format fitting the media, else a single-video format, else the default),
  `enabledPlatforms()`, `toFrontend()`. Value objects `App\Support\Publishing\PlatformSpec` /
  `FormatSpec`. `Platform::captionLimit()` etc. only delegate to it; `MediaRatio::optionsFor()` and
  `ImageAspectRatio::forPlatform()` read it too.
- Shared to every Inertia page as the `platforms` prop (`Inertia::once`), read with
  `usePlatforms()` / `capabilityOf()` / `resolveFormat()` in `resources/js/lib/platforms.ts`.
- Grep test (`PlatformCapabilitiesTest::test_no_platform_limit_is_hard_coded_outside_the_registry`)
  fails on a distinctive caption limit or a `Platform::X => <number>` rule anywhere in `app/`.

### Capabilities (M-0.2 audit, against docs.upload-post.com on 19 Oct 2026)
| Platform | Publish | Formats (vendor media type) | First comment | Alt text | Link | Notes |
|---|---|---|---|---|---|---|
| Instagram | Upload-Post | feed (IMAGE), carousel ≤10, reel (REELS), story (STORIES) | yes | yes (`instagram_alt_text`) | no (bio) | cover `cover_url`, `thumb_offset` |
| TikTok | Upload-Post | video, photo mode ≤35 | yes | no | no | cover `tiktok_cover_image_url`, `cover_timestamp` |
| Facebook | Upload-Post | post (POSTS/VIDEO), reel (REELS), story (STORIES) | yes | yes | in caption | `facebook_page_id` setting |
| LinkedIn | Upload-Post | post (text, ≤20 images, video) | yes | yes | in caption | `target_linkedin_page_id`; AI images never re-encoded |
| YouTube Shorts | Upload-Post (`/upload`) | short (≤180s, vertical) | yes | no | in description | `youtube_title` = caption's first line (≤100), `privacyStatus=public` |
| Google Business | Upload-Post | update (1 image/≤30s video) | no | no | `gbp_cta_url` + LEARN_MORE | hashtags dropped; `gbp_location_id` setting |
| Threads | Upload-Post | post (text, ≤20 media) | yes | yes | in caption | 500 chars, 1 topic tag |
| Pinterest | Upload-Post | pin, carousel 2–5 | no | yes | `pinterest_link` | **requires** `pinterest_board_id`; not on the hosted connect page's `platforms` list |
| X | Upload-Post | post (text, ≤4 images, video) | yes | yes | in caption | 280 chars; no fold spacer |
| Bluesky | — | — | — | — | — | **Deferred** (MVP note: only if cheap; not in this phase) |

Cancel/edit: `DELETE` / `PATCH /uploadposts/schedule/{job_id}` (media, profile and platforms can't be
edited, so we cancel and re-submit). Connect links: `generate-jwt` `access_url`, valid 48h, meant to
be handed to end users (used by M-2.8). Webhooks: `POST /uploadposts/users/notifications` on
`app.upload-post.com` returns `webhook_secret`.

#### Analytics & comments (M-0.2 audit, docs.upload-post.com `get-analytics` / `comments`, 19 Oct 2026)
Endpoints: profile metrics `GET /analytics/{profile}` (followers, reach, views, impressions,
profileViews, likes, comments, shares, saves), totals `GET /uploadposts/total-impressions/{profile}`,
per post `GET /uploadposts/post-analytics/{request_id}` / `?platform_post_id=` (live) / `/cached`
(fields vary; `available_metrics` lists them), and the public metric catalogue
`GET /uploadposts/platform-metrics`. Comments: `GET /uploadposts/comments`,
`POST /uploadposts/comments/create` (post or reply), `DELETE …/comments/delete`,
`POST …/comments/action` (hide / like / pin). **M-9.1: the comments GET/create endpoints are now
called by `App\Services\Inbox\UploadPostCommentSource`** (the social inbox) for the seven supported
networks; hide/like/pin and delete remain unused. See `inbox.md`.

| Platform | Account metrics (M-3) | Post metrics (M-3) | Read comments (M-9) | Reply (M-9) | Source |
|---|---|---|---|---|---|
| Instagram | yes | yes | yes | yes (needs `comment_id`) | Upload-Post; Meta Graph fallback |
| Facebook | yes | yes | yes | yes | Upload-Post; Meta Graph fallback |
| TikTok | yes (+ audience insights) | yes | yes (reconnect first) | yes | Upload-Post |
| LinkedIn | yes | yes | yes | yes | Upload-Post |
| YouTube Shorts | yes (+ watch time) | yes | yes | yes | Upload-Post |
| Threads | yes | yes | yes | yes (can't delete) | Upload-Post |
| X | yes | live only (not cached) | yes | yes | Upload-Post |
| Pinterest | yes (+ pin / outbound clicks) | yes | no | no | Upload-Post; comments **deferred** |
| Google Business | yes | **no** | no | no | Upload-Post account level only; per post and reviews **deferred** (B-11.5 if needed) |

Dependent phases: **M-2.8** connect link → Upload-Post `access_url` (confirmed hand-off-able).
**M-3.1** metrics → Upload-Post for all nine networks (Google Business per post shows "not available
for Google Business"). **M-9.1** comments → Upload-Post for seven networks, Pinterest and Google
Business deferred; the M-9.1 `CommentSource` contract's Meta Graph implementation is only needed if
Upload-Post's Instagram/Facebook comments prove unreliable in the sandbox.

#### Meta app (Graph) scopes
The direct Meta app (`config/services.php` `facebook`) only requests `instagram_basic` +
`instagram_content_publish` (audio search, Step 1.9; token refresh, M-2.7). Upload-Post holds its own
Meta app approval for publishing, insights and comments, so **M-3 and M-9 need no new Meta scopes**
while Upload-Post is the source. If the Meta Graph fallback is ever built, it needs
`instagram_manage_insights` + `read_insights` (M-3) and `instagram_manage_comments` +
`pages_manage_engagement` + `pages_read_engagement` (M-9), each through App Review. Budget several
weeks of lead time. The current review status must be read from the Meta developer dashboard, which
this audit can't reach.

#### Vendor cost (upload-post.com pricing, 19 Oct 2026, USD monthly / annual per month)
Free 2 profiles (10 uploads) · Basic $24 / $16, 5 profiles · Professional $50 / $33, 25 · Advanced
$147 / $118, 75 · Business $438 / $350, 225 (unlimited via API). Add-on packs of 5 to 50 profiles at
$15 to $115. Uploads are unlimited on paid plans.
- **Today each org brings its own Upload-Post key** (`organizations.upload_post_key`), so the vendor
  cost sits with the agency, not Alima. M-10 must decide whether that stays or moves to one platform
  key (the M-10.1 margin question).
- `UploadPostAdapter::connectAccount()` creates **one Upload-Post profile per `SocialAccount`**. One
  Upload-Post profile can hold every network for a brand, so mapping profiles per client would cut
  profile usage by about 4×. Example at about 4 accounts per brand on per-account profiles: Studio
  (6 brands ≈ 24 profiles) → Professional $50. Agency (15 ≈ 60) → Advanced $147. Agency Plus
  (40 ≈ 160) → Business $438, close to the £399 price. Per brand: 6 / 15 / 40 profiles → Basic +
  5-pack ($39) / Professional / Advanced. Revisit before M-10.1.

### Per-channel content & formats (M-2.2 / M-2.3, I-3.1 / I-3.2)
- `UploadPostAdapter::buildFields()` publishes the *target's* effective caption / hashtags / link
  (override or master) composed by `App\Support\Publishing\CaptionComposer` (link appended only
  where there's no link field and caption links work; hashtags dropped where unused; dot-dash
  spacer only where `hashtag_spacer`), sends `{prefix}_title` always, `description` where
  `vendor_description`, `{prefix}_first_comment`, `{prefix}_alt_text` (JSON list in slide order),
  the format's media type (`media_type` / `facebook_media_type`, `vendor_media_type_video` for a
  Facebook video post), the video cover/offset, the account's registry settings, and the YouTube /
  Pinterest short title, Google CTA.
- Images are cropped per target format (`PublishImageCropper::targetRatio()` takes the
  `FormatSpec`: `crop_to` wins — Stories 9:16 — then a fixed post format, then the format's aspect
  range clamp). Carousel order = `post_assets.sort_order`.

### Cancel, edit after scheduling, webhook (M-2.5 / I-3.6)
- Contract: `cancel(PostTarget): CancelResult` (authenticates per org; only a confirmed 2xx is
  success, 404/409 = `tooLate`), `registerWebhook(Organization, url): ?string`.
- A channel sent with a future `scheduled_date` is **vendor-held** (`PostTarget::isVendorHeld()`);
  `Post::canBeRecalled()` = `publishing`, at least one held, every channel held or failed.
- `App\Services\Publishing\VendorScheduleService::recall()` cancels held channels *before* any
  change (throws a validation error naming the channel if the vendor refuses — nothing changes),
  `resubmit()` sends recalled channels again after commit (new attempt ids, Step I-1.3 batch).
  Used by: unschedule (`publishing → approved/draft`, new map edges guarded by policy +
  `TransitionPostStatusAction`), `UpdatePostAction`, `ReschedulePostAction`,
  `PauseCampaignAction`, `ShiftCampaignAction` (recalls before its transaction).
- `RegisterUploadPostWebhookAction` runs after commit whenever a new Upload-Post key is saved
  (unless a secret was typed in with it) and stores the returned secret.

### Hand-off at scheduling time (2026-10-20)
- Scheduling a post now sends it to Upload-Post straight away. `TransitionPostStatusAction::
  dispatchPublishJob()` queues `App\Jobs\SendScheduledPostToVendorJob` (undelayed, `publishing`
  queue, unique per post + dispatch id) next to the delayed `PublishPostJob`. It sends every
  unsent channel due more than `LEAD_MINUTES` (5) ahead through the normal `PublishPostTargetJob`
  batch, so the adapter adds `scheduled_date` and the vendor holds it. The post **stays
  `scheduled`**, so reminders, warnings, analytics and the portal see it as before. Channels due
  sooner are left for `PublishPostJob`.
- `PublishPostJob` at the post's time still moves it to `publishing`, but skips channels the
  vendor already has (pending with an `external_post_id` or a request in flight); the webhook/poll
  then finalizes the post.
- `Post::isHeldByVendor()` = `scheduled` with at least one `isVendorHeld()` channel. Such a post is
  recalled (`VendorScheduleService::recall()`, refused = nothing changes) before:
  leaving `scheduled` for anything but `publishing` (`PostStatusTransitionMap::leavesSchedule()`
  in `TransitionPostStatusAction`; non-published channels are reset), `ReschedulePostAction` /
  `UpdatePostAction` (re-dispatch sends them again), `ShiftCampaignAction` (before its
  transaction), and `DeletePostAction`.
- `VendorScheduleService::releaseScheduled(Builder)` is the best-effort form (a refusal is logged
  as an error and the action carries on), used by `ArchiveClientAction`,
  `SuspendOrganizationAction` and `DeleteOrganizationAction`. Restoring / reinstating sends the
  posts at their time through `PublishPostJob`.
- A rejection at hand-off fails that channel (error shown on it, `warning` log
  `PublishPostTargetJob: vendor rejected the channel`); the post stays `scheduled` and
  `PublishPostJob` retries the channel when it's due.
- `PollPendingPublishStatusesAction` skips channels whose `scheduled_at_utc` is still in the future,
  and the delivery timeout counts from the later of `updated_at` and `scheduled_at_utc`, so a
  channel the vendor holds for days isn't failed as "no delivery confirmation".
- `PublishPostJob::pausedReason()` (archived client / deleted or suspended org) is shared by both
  jobs.
- Tests: `tests/Feature/Publishing/ScheduledPostHandOffTest.php`.

### More networks & rollout (M-2.6 / I-3.4)
- `Platform`: YouTube, GoogleBusiness, Threads, Pinterest, X. `PlatformRolloutStage`: YouTubeShorts,
  GoogleBusiness, Threads, Pinterest, X, each with a `PUBLISH_ROLLOUT_*` flag (default on) and
  `forPlatform()`. The editor and the account form only offer enabled platforms; the connect page is
  narrowed to the account's network where `hosted_connect`.

## Phase M-3 additions (Step M-3.1)
- `SyncPublishStatusAction` takes an optional `VendorPostRef` (`App\Support\Publishing`): a
  confirmed target stores `published_at` plus the vendor's `request_id` (`vendor_request_id`), the
  network's post id (`result.publish_id` / `post_id` → `platform_post_id`) and `result.url`
  (`post_url`, http(s) only). The webhook passes them; `UploadPostAdapter::checkStatus()` returns
  them on `TargetResult::$ref` for the poll path.
- Engagement reads live behind their own contract, `App\Contracts\EngagementSource`
  (`UploadPostEngagementSource`), not on `PublishAdapter` — see `analytics.md`. The registry's
  `account_metrics` / `post_metrics` flags say where metrics exist (Google Business: account only).

## Phase M-1 additions (Step M-1.1 / I-16.1)
- `post_targets.publish_correlation_id` (migration `2026_10_22_100000_…`): the correlation id of
  the request/job that sent the target to the vendor, stamped next to `publish_requested_at` in
  `PublishPostTargetJob::claim()`.
- `PublishPostJob`, `SendScheduledPostToVendorJob` and `PublishPostTargetJob` add `post_id` /
  `post_target_id` to `Context`, so every log line and error report for a publish carries them.
- `UploadPostWebhookController` adds `post_id`, `post_target_id` and `publish_correlation_id` to
  `Context` once it has matched the target (join a webhook back to the request that scheduled the
  post), and calls `OpsAlerter` on an invalid signature (ids and IP only, never the signature).
- A job on the `publishing` queue that fails for good is reported to error tracking with that
  context and alerts on call (`AlertOnFailedPublishingJob`, see `ops.md`).

## Phase B-5 — Publishing without vendor keys (business workspaces)

### Credentials seam (B-5.1)
- `App\Contracts\PublishCredentials` (bound to `App\Services\Publishing\OrganizationOrPlatformCredentials`
  in `AppServiceProvider`) is now the **single seam** for vendor credentials — nothing reads
  `organizations.upload_post_key` / `upload_post_webhook_secret` directly any more. Methods:
  `mode(Organization): App\Enums\PublishMode` (`byo`|`managed`), `apiKeyFor(SocialAccount)`,
  `apiKeyForOrganization(Organization)`, `webhookSecretFor(Organization)`, `profileFor(SocialAccount)`.
- **byo** (the agency default, unchanged): the org's own `upload_post_key`/`upload_post_webhook_secret`,
  a per-account vendor profile (`external_account_id ?? external_profile_id ?? id`). **managed**
  (every business workspace, plus any agency a super admin flips via `organizations.publish_mode`):
  Alima's single `config('services.upload_post.platform_key')` / `platform_webhook_secret`
  (env `UPLOAD_POST_PLATFORM_KEY` / `UPLOAD_POST_PLATFORM_WEBHOOK_SECRET`), and the org's own isolated
  profile `organizations.upload_post_profile`, minted lazily by
  `App\Actions\Workspace\EnsureManagedPublishProfileAction` (DB only — the vendor-side profile is still
  created idempotently by `UploadPostAdapter::ensureProfileExists()` on first connect). No managed org
  can ever resolve another org's profile. Keys/secrets are never logged.
- `UploadPostAdapter` resolves the key (`baseRequest`/`verifyCredentials`/`registerWebhook`) and the
  publish profile (connect `username`, publish `user` field) through the contract;
  `UploadPostWebhookController` resolves the signing secret through it (managed deliveries route to the
  right org by their unique `job_id`, then verify with the platform secret);
  `UploadPostMusicProvider` and `UploadPostEngagementSource` resolve the key through it.
  `RegisterUploadPostWebhookAction` stays byo-only (a managed org has no per-org key to save).

### Plain-language publish failures (B-5.3)
- `App\Enums\PublishFailureReason`: `classify(?string $error, ?Platform): self` maps a raw vendor
  error to a cause (`token_expired`, `video_too_long`, `media_too_large`, `unsupported_media`,
  `link_rejected`, `duplicate_content`, `rate_limited`, `permission_denied`, `not_enabled_yet`,
  `unknown`), each with `message(?Platform)` (brand-voice copy), `fix(?Platform)` (the one next step),
  `isRetryable()` and `needsReconnect()`. `describe()` returns the frontend/notification payload.
- The editor's target props (`PostController::buildPostData`) carry a `failure` object per `failed`
  target; `resources/js/components/post-editor/workflow/workflow-rows.tsx` renders each failed channel
  with its reason + fix and a **per-channel Retry** (plus "Retry all" when more than one can retry).
- `RetryFailedPostTargetsAction(Post, User, ?PostTarget $only = null)` — `$only` resends just that one
  failed channel (the per-channel Retry posts `target=<id>` to `posts.retry-failed-targets`, validated
  to belong to the post); omitting it resends all failed channels as before.
- `App\Notifications\PostPublishFailed` now sends **email + the in-app bell for business orgs**
  (agencies keep database-only) and carries the classified per-channel reasons in both `toArray` and
  `toMail`, so no business failure is silent.

## Routes / Controllers
- No dedicated `/posts/:id/schedule` route — Step 1.4 reuses the existing `POST
  /posts/{post}/transition` endpoint (`posts.transition`) as the schedule trigger. See
  `posts.md` for the full flow (`TransitionPostRequest` re-validates the §6 checklist before
  `scheduled` is even a legal `to` value; `TransitionPostStatusAction` enqueues `PublishPostJob`
  once the transition to `scheduled` lands).
- `POST /social-accounts/{socialAccount}/connect` (`social-accounts.connect`, authed, Step 1.3;
  bugfix): `SocialAccountController::connect` authorizes `update` on the account, calls
  `App\Actions\SocialAccounts\ConnectSocialAccountAction` (thin wrapper around
  `PublishAdapter::connectAccount()`), and returns the vendor URL as **JSON** (`{url}`, or `{message}`
  with a 422 status if the adapter throws `RuntimeException`) instead of redirecting the browser.
  This lets the frontend open the vendor's hosted connect page in a separate popup/tab (via
  `resources/js/pages/clients/show.tsx`'s `handleConnect`) rather than navigating the user's current
  Graphy tab away — see Known Gaps/Changelog for why the earlier `Inertia::location()` redirect was
  replaced.
- `GET /social-accounts/callback` (`social-accounts.callback`, public, Step 1.3; bugfix):
  `SocialAccountController::callback` calls `PublishAdapter::handleCallback($request->query())`
  directly (no Action — the adapter method is already the seam per the Step 1.1 contract), then
  renders `resources/views/social-accounts/connected.blade.php` — a small standalone (non-Inertia)
  page shown inside the connect popup/tab, which `postMessage`s a
  `{type: 'graphy:social-account-connected'}` confirmation to `window.opener` and auto-closes itself
  after ~1.2s. The main Graphy tab listens for that message (or, as a fallback, polls whether the
  popup has closed) and reloads the social accounts list, so `connectionStatus` updates without a
  manual page refresh.
- `App\Jobs\PublishPostJob` (Step 1.4; revised 2026-09-20) is the only caller of
  `PublishAdapter::publish()`. It's dispatched by `TransitionPostStatusAction` whenever a post
  lands in `scheduled`, delayed until the earliest target's `scheduled_at_utc` (immediate if
  already due/past). Since 2026-10-20 the channels due later are already with the vendor by then
  (see "Hand-off at scheduling time") and are skipped. On run: re-checks the post is still `scheduled` (no-ops otherwise — e.g. it
  was manually reverted), transitions to `publishing`, calls `publish()` for every target's
  `SocialAccount`, and persists each `TargetResult` onto the matching `PostTarget`
  (`status`/`external_post_id`/`error`, columns from Step 1.4's migration). A `TargetResult::ok`
  target is now left `pending` (not immediately `published`) — the vendor accepting the request
  only means it was queued/scheduled on their end, not that it actually posted yet — and the post
  itself stays `publishing`; only a target that's rejected outright (`ok: false`) moves the post
  straight to `failed` via `TransitionPostStatusAction`. Every accepted target now genuinely relies
  on the webhook/poll machinery (`SyncPublishStatusAction`) to confirm real vendor delivery and
  move the post to `published`, closing the gap where Graphy showed `published` for a post that
  might not actually reach the social platform until later (see 2026-09-20 changelog entry).
- `GET /posts/{post}/music-search` (`posts.music-search`, authed + mirrored under `preview.`, Step
  1.8.4): `PostController::musicSearch` authorizes `view` on the post, validates `query` (required
  string) and `platform` (required, a valid `Platform` value), and short-circuits to `{tracks: []}`
  for any platform other than Instagram/TikTok without ever calling `SearchMusicAction` — matching
  Step 1.8.2's real vendor coverage. Otherwise delegates to `SearchMusicAction` and returns each
  `MusicTrack` as `{id, name, artist, preview_url}` JSON. When `MusicProvider` throws
  `App\Exceptions\Publishing\MusicProviderUnavailableException` (Step 1.8.6 bugfix — see below),
  the response is `{tracks: [], error: '<reason>', error_code: '<code>'}` instead of a bare empty
  result, so the editor can tell the user *why* (no connected account, vendor rejected the
  request, TikTok needs reconnecting, Instagram not connected via Facebook) rather than assuming
  search is broken. `error_code` (Step 1.9.5, e.g. `meta_not_connected`) is the exception's
  `errorCode()` — `null` unless the provider set one — and lets the editor UI render an actionable
  link (e.g. the Facebook connect route below) instead of just text. This is the first (and still
  only) caller of `SearchMusicAction`.
- `GET /clients/{client}/social-accounts/{socialAccount}/facebook/connect` /
  `.../facebook/callback` (`social-accounts.facebook.connect`/`.callback`, authed, Step 1.9.3):
  see `.junie/modules/social-accounts.md` for the full additive Meta Graph API connect flow this
  adds — `App\Http\Controllers\Auth\FacebookInstagramConnectController`, feeding
  `MetaGraphMusicProvider` above.
- `POST /webhooks/upload-post` (`webhooks.upload-post`, public, Step 1.5, confirmed against
  https://docs.upload-post.com/api/webhooks): `UploadPostWebhookController::handle` only acts on
  the `upload_completed` event (`X-Upload-Post-Event` header, or the payload's `event` field);
  other events (`social_account_connected`/`_disconnected`/`_reauth_required` — account-health
  notifications unrelated to a specific publish) are acknowledged without action. Reads `job_id`
  and `platform` from the payload and looks up the matching `pending` `PostTarget` — matched on
  both, since one `job_id` fans out to one webhook event per platform when a post targets several
  platforms. Once a target is found, verifies `X-Upload-Post-Signature: sha256=<hex>` against
  `HMAC_SHA256(webhook_secret, "{X-Upload-Post-Timestamp}.{raw body}")`, where `webhook_secret` is
  that target's account's organization's `upload_post_webhook_secret` (resolved from the target
  rather than a global config, since the secret is per-account) — a missing/mismatched signature or
  a stale/missing timestamp (>5 minutes old) is rejected with 401, while an org with no secret
  configured yet skips verification with a logged warning. Reads `result.success`
  (bool)/`result.error` from the payload to finalize the target via `SyncPublishStatusAction`.
  Always responds `200` (even for an unknown/already-resolved target, or a non-publish event) so
  the vendor doesn't retry indefinitely; only a bad signature or a missing `job_id`/`platform`
  yields a non-200. Excluded from CSRF verification in `bootstrap/app.php` (`webhooks/*`), like any
  other server-to-server callback.

## Business Rules
- The interface is the single seam between the app and any publishing vendor — no Action/Controller
  should call a vendor SDK/HTTP client directly; they depend on `App\Contracts\PublishAdapter`.
- `NullPublishAdapter` intentionally fails every `publish()` target and reports unhealthy accounts, so
  accidentally binding it in a non-test environment is loud rather than silently "succeeding".
- `UploadPostAdapter` always authenticates with the *target account's own organization's*
  `upload_post_key` — never a shared/global key — so a per-org key rotation or omission only affects
  that org's publish calls.
- `TargetResult::sentFields`/`skippedFields` must stay accurate: any field the adapter didn't actually
  send (because the source data was empty) belongs in `skippedFields`, never silently omitted, so
  callers/UI can surface "this field wasn't sent" without inspecting the raw payload.
- `MusicProvider` is the same kind of seam as `PublishAdapter` — no Action/Controller should call
  a vendor sound/music endpoint directly; they depend on `App\Contracts\MusicProvider`.
  `UploadPostMusicProvider` never calls the vendor for a platform it can't actually query (only
  TikTok today) — it returns an empty-collection/`null` result instead, matching the pattern
  `PlatformRolloutGate` already uses to keep an unsupported target from ever reaching Upload-Post.
- Step 1.8.6 bugfix: when TikTok search/find *can't* reach a real answer for a reason the editor
  should actually know about — no connected TikTok `SocialAccount` for the org, the vendor request
  failing outright, or the vendor rejecting it (e.g. `tiktok_reconnect_required` when the linked
  profile's TikTok OAuth has expired/was never completed) — `UploadPostMusicProvider` now throws
  `App\Exceptions\Publishing\MusicProviderUnavailableException` with a user-facing message instead
  of silently returning an empty `Collection`. Previously the editor's search box just showed no
  results with no explanation, which looked identical to "search is broken" whether the real cause
  was a genuinely unsupported platform (Instagram — still returns empty, by design, no vendor
  endpoint exists) or a fixable connection problem (TikTok). `PostController::musicSearch` catches
  this specific exception and returns `{tracks: [], error: message}`; the editor
  (`resources/js/pages/posts/edit.tsx`) shows `error` in the same place it already shows network
  failures.

## Tests
- `tests/Feature/Publishing/PublishPipelineTest.php` (Steps I-1.2/I-1.3): unschedule → reschedule
  leaves one effective publish; dragging a scheduled post re-dispatches and the stale job no-ops; a
  post moved earlier is queued for the new time; one target failing while another succeeds ends
  `partially_published` (with the error on the log and the org notified); retry resends only failed
  targets (and is forbidden for viewers / non-failed posts / the manual transition); running the
  same job twice publishes once; a target job never resends an attempt that already reached the
  vendor; a stale target job does nothing; both jobs' `failed()` handlers leave no post stuck;
  publish fans out one batched job per target; the delivery-confirmation timeout fails the target.
- `tests/Unit/Services/Publishing/UploadPostAdapterResilienceTest.php`: status checks retry on 5xx;
  the publish POST is not retried on 5xx but is on 429, with the attempt id as `Idempotency-Key`;
  a past-due target is sent without `scheduled_date`; backoff grows exponentially with jitter.
- `tests/Unit/Jobs/PublishPostJobTest.php` rewritten for the fan-out (accepted target stays
  `pending` with an attempt id, rejection fails the post, non-scheduled posts skip, uniqueness and
  retry settings).
- `tests/Unit/Services/Publishing/NullPublishAdapterTest.php`: asserts `NullPublishAdapter` satisfies
  `PublishAdapter` and can be bound, and that each contract method behaves as a no-op (`connectAccount`
  returns `''`, `publish()` returns one failed `TargetResult` per target, `health()` is `false`,
  `handleCallback`/`cancel` don't throw).
- `tests/Unit/Services/Publishing/UploadPostAdapterTest.php`: asserts `UploadPostAdapter` is the default
  binding, and — using `Http::fake()` — that `publish()` sends the correct request (URL, `Authorization:
  Apikey {org upload_post_key}`, body fields) for LinkedIn (common fields only), Instagram (location/
  media_type/audio mapping), and TikTok (music/location mapping, `is_ai_generated` skipped for
  non-AI media; `tests/Feature/AI/ImageStudioHousekeepingTest.php` asserts it is sent for AI media), plus that a non-2xx vendor response yields a failed `TargetResult` with the vendor's error.
  Also covers `checkStatus()`'s resolved (matching-platform and top-level-fallback)/still-processing/
  not_found outcomes against the real `/uploadposts/status` shape (Step 1.5).
- `tests/Unit/Actions/Publishing/SyncPublishStatusActionTest.php` (Step 1.5): asserts a single-target
  post is finalized `published`/`failed` from a vendor verdict, that a post with multiple targets
  waits for every target before finalizing, and that a target whose post already moved on is still
  updated but doesn't re-trigger a transition.
- `tests/Unit/Actions/Publishing/PollPendingPublishStatusesActionTest.php` (Step 1.5): asserts a
  resolved pending target is finalized via a mocked `PublishAdapter::checkStatus()`, that a `null`
  (still-processing) result leaves the target untouched, and that a target pending for less than
  the configured threshold is skipped entirely (adapter never called).
- `tests/Unit/Services/Publishing/UploadPostMusicProviderTest.php` (Step 1.8.2, updated Step 1.8.6):
  asserts `UploadPostMusicProvider` is the default `MusicProvider` binding, and — using
  `Http::fake()` — that `search()` sends the correct request (`profile` from the acting org's
  connected TikTok account, `q`, `Authorization: Apikey {org upload_post_key}`) and maps `tracks[]`
  to `MusicTrack` DTOs; that `search()`/`find()` never call the vendor for Instagram (still an
  empty-collection/`null`, since no vendor endpoint exists there); and that `search()` now *throws*
  `MusicProviderUnavailableException` (rather than silently returning empty) for an org with no
  connected TikTok account, when the vendor request fails outright, and when the vendor rejects it
  — including a dedicated reconnect-specific message for `error_code: tiktok_reconnect_required`.
  `find()` matches a track by `id` out of the same search response.
- `tests/Feature/UploadPostWebhookControllerTest.php` (Step 1.5): asserts an `upload_completed`
  event with `result.success` true/false transitions a `publishing` post with a single pending
  target to `published`/`failed` respectively (the plan's §12-style acceptance scenario), that an
  invalid signature or a stale timestamp is rejected with 401 (and leaves the post untouched) when
  the org has a webhook secret configured, that a validly-signed payload is accepted, that an
  unknown `job_id` is acknowledged (200) without error, and that unrelated event types are
  acknowledged without action.
- `tests/Unit/Contracts/MusicProviderTest.php` (Step 1.8.1): an anonymous class implementing
  `MusicProvider` asserts the interface's `search()`/`find()` signatures compile and behave as
  expected (returns a `Collection<MusicTrack>`/`?MusicTrack`).
- `tests/Unit/Support/Publishing/MusicTrackTest.php` (Step 1.8.1): asserts `MusicTrack` exposes its
  readonly fields and that `artist`/`previewUrl` accept `null`.
- `tests/Unit/Actions/Publishing/SearchMusicActionTest.php` (Step 1.8.3): asserts `SearchMusicAction`
  delegates the given `(query, platform)` to a mocked `MusicProvider::search()` and returns its
  result unchanged, and that it returns an empty collection when the provider finds nothing.
- `tests/Feature/PostEditorControllerTest.php` (Step 1.8.4, updated Step 1.8.6, 1.9.5): asserts
  `PostController::musicSearch` returns a mocked `MusicProvider`'s tracks as JSON for TikTok, that
  it never calls the provider (returns `{tracks: []}` immediately) for a platform like Facebook,
  that a `MusicProviderUnavailableException` thrown by the provider is caught and surfaced as
  `{tracks: [], error: message}` rather than propagating as a 500 or a bare empty result, and that
  a `meta_not_connected`-coded exception surfaces that same distinguishable `error_code`.
- `tests/Unit/Config/ServicesFacebookConfigTest.php` (Step 1.9.1): asserts `services.facebook`
  resolves `null` client id/secret/redirect and sane Graph base URL/version defaults when the
  `FACEBOOK_*` env vars are unset — the app must still boot.
- `tests/Unit/Models/SocialAccountTest.php` (Step 1.9.2): asserts `meta_access_token` round-trips
  through the `encrypted` cast and is never stored in the DB in plaintext.
- `tests/Feature/FacebookInstagramConnectControllerTest.php` (Step 1.9.3): asserts `redirect()`
  sends the browser to Facebook's OAuth dialog, that `callback()` exchanges a faked authorization
  code/token pair and persists `meta_access_token`/`meta_access_token_expires_at`/
  `meta_instagram_user_id` on the right `SocialAccount`, and that a Designer is forbidden from
  starting the connect flow (mirrors `SocialAccountPolicy::update`).
- `tests/Unit/Services/Publishing/MetaGraphMusicProviderTest.php` (Step 1.9.4): asserts real
  Instagram search results from a faked Graph `ig_audio` response, that TikTok still delegates
  unchanged to `UploadPostMusicProvider`, and the `meta_not_connected` "not connected" error path.
  `tests/Unit/Services/Publishing/UploadPostMusicProviderTest.php` was updated to assert the
  default `MusicProvider` binding is now `MetaGraphMusicProvider` instead.
- `tests/Feature/MetaAudioSelectionAcceptanceTest.php` (Step 1.9.6, §12-style): repeats the Step
  1.8.5 acceptance pattern using the real `MetaGraphMusicProvider` (only the outbound Graph HTTP
  call faked) — a selected Instagram track's id round-trips through `Post::music` into
  `UploadPostAdapter::publish()`'s `audio_id` field, unchanged from the Step 1.2 mapping.

## Known Gaps / Open Questions
- Phase M-2 (2026-10-20): field names for the five new networks come from Upload-Post's docs and are
  covered by faked-vendor tests only — confirm each against a live sandbox before relying on it, and
  switch a flag off (`PUBLISH_ROLLOUT_*`) if it disagrees. Pinterest isn't on the hosted connect
  page's documented `platforms` list (the Connect API `oauth/pinterest/start` may be needed).
- Bluesky deferred (MVP note). Upload-Post pricing recorded in the M-0.2 audit above (2026-10-19);
  a live sandbox check of the analytics/comment fields is still due before M-3.1 / M-9.1.
- Video probing needs ffprobe/ffmpeg on the worker (`FFPROBE_PATH`, `FFMPEG_PATH`); without them
  videos stay unprobed and the checklist skips length/shape checks.
- A vendor-held recall inside `PauseCampaignAction`'s transaction is safe for per-post refusals
  (caught) but an unrelated later exception would roll back local resets of already-cancelled
  channels; shift recalls before its transaction for this reason.
- Editing a post while channels are vendor-held always recalls + re-submits (even with no real
  change); the vendor refunds a cancelled job's credits.
- Resolved 2026-09-20: `PublishPostJob` used to resolve every target's `published`/`failed` status
  synchronously from the immediate HTTP response to `publish()`, which meant a post scheduled with
  Upload-Post could show `published` in Graphy well before the vendor actually delivered it (it
  only means the request was *accepted*). `PublishPostJob` now leaves an accepted target `pending`
  and the post `publishing`, relying on the already-built `SyncPublishStatusAction`
  webhook/poll machinery to confirm real delivery before marking `published`. One residual risk: if
  an org has no `upload_post_webhook_secret` configured *and* the vendor never resolves a
  `checkStatus()` call to `completed`/`failed`, a post could stay `publishing` indefinitely with no
  automatic timeout/alerting — worth revisiting (e.g. a max-age check in
  `PollPendingPublishStatusesCommand`) if this is observed in practice.
- Resolved (Step 1.6 / M-2.5 / M-2.7): `health()` is called by `CheckSocialAccountHealthAction`, and
  `cancel(PostTarget)` is a real vendor call (see "Cancel, edit after scheduling" above).
- The webhook signature/payload contract and the `checkStatus()` polling endpoint are now confirmed
  against Upload-Post's real docs (https://docs.upload-post.com/api/webhooks and
  https://docs.upload-post.com/api/upload-status respectively) — no longer open questions. One
  remaining gap: `upload_post_webhook_secret` currently has no automated way to be populated (no
  code yet calls `POST /uploadposts/users/notifications` to register the webhook URL and receive
  the vendor-generated `whsec_...` secret); it must be set manually via the organization settings
  page after registering/reading it from the Upload-Post dashboard
  (https://app.upload-post.com/notifications). Automating that registration call is a natural
  follow-up once a per-org "configure publish integration" onboarding flow exists.
- Step 1.4 deliberately did **not** add a separate `POST /posts/:id/schedule` route as literally
  named in the plan. Step 0.10 had already built a checklist-gated `scheduled` destination into
  the generic `POST /posts/{post}/transition` endpoint (`TransitionPostRequest` blocks the move
  unless the §6 checklist passes), and the frontend editor already only enables its "Move to
  Scheduled" button once the checklist passes. Adding a second endpoint that duplicated this gate
  would have meant two code paths that could reach `scheduled` with different rules. Instead,
  `TransitionPostStatusAction` enqueues `PublishPostJob` right after a successful move to
  `scheduled`, keeping one single entry point. Revisit if the plan's literal endpoint shape turns
  out to matter for some external integration.
- Resolved 2026-10-18 (Step I-1.3): `PublishPostJob` now has retries/backoff/timeout, a
  `failed()` handler, per-target fan-out and a "Retry failed targets" action; the stuck-in-
  `publishing` risk is closed by the delivery-confirmation timeout. Still open: no external
  dead-letter/alerting beyond logs, notifications and the Home row (I-16).
- Upload-Post doesn't document an idempotency header; `Idempotency-Key` is sent anyway (harmless if
  ignored). The real double-post protection is at-most-once per attempt (`publish_requested_at`)
  plus "retry on 429 only" for the publish POST.
- Resolved 2026-10-20 (M-2.5 / I-3.6): a future `scheduled_date` is now vendor-held and cancelled /
  re-submitted through `VendorScheduleService`; delayed local jobs remain for immediate sends.
- Resolved 2026-10-18 (Step I-1.6): the Step 1.3 callback now requires a signed, single-use `state`
  (`ConnectStateStore`, see `social-accounts.md`); `PublishAdapter::connectAccount()` takes it as a
  second argument and `UploadPostAdapter` round-trips it on `redirect_url`. Originally: the
  callback had no signature/token verification — it trusted whatever query string the
  browser is redirected with. This mirrors the unauthenticated `review/{token}` portal in spirit but
  has no per-request secret; revisit once Upload-Post's real callback contract (and whether it
  supports a verifiable state/signature param) is known, before Step 1.5's webhook (which the plan
  explicitly calls out for signature verification).
- The popup `postMessage` confirmation relies on the popup keeping a live `window.opener` reference
  and matching origin; if a browser/extension strips `opener` (e.g. `noopener` contexts) the message
  never arrives, but the frontend's "popup closed" polling fallback in `handleConnect` still refreshes
  the accounts list a few seconds later, so the UI doesn't get permanently stuck.
- `connect()`'s plain `fetch()` call authenticates via the `XSRF-TOKEN` cookie (read client-side and
  sent as `X-XSRF-TOKEN`) rather than Inertia's own request pipeline, since it deliberately isn't an
  Inertia visit (it needs the JSON URL back without navigating the current tab). This relies on
  Laravel's default `VerifyCsrfToken` support for that header; revisit if CSRF cookie behavior changes
  (e.g. a stricter `SameSite`/domain config).
- Resolved 2026-10-20 (M-2.3): `post_targets.format` makes Stories explicit and `STORIES` is sent as
  the vendor media type. AI disclosure is now derived
  from attached assets (Step 16.13, see above); manually uploaded AI art isn't flagged since only
  Image Studio assets carry `is_ai_generated`.
- The connect/callback endpoint shapes (`POST /uploadposts/users/generate-jwt`, `access_url`/`url` as
  the returned link) are still interim/unverified against a live sandbox. The auth scheme is confirmed
  from Upload-Post's docs: every request must send `Authorization: Apikey <key>` (not `Bearer`) — using
  `Bearer` causes Upload-Post to reject the request with a misleading `401 Invalid or expired token`
  even when the key itself is valid. (The publish endpoint itself was fixed 2026-09-20 — see Changelog.)
- Resolved 2026-10-20 (M-2.5): the contract is now `cancel(PostTarget)`, which authenticates per org.
- `App\Contracts\MusicProvider`/`UploadPostMusicProvider` (Step 1.8.2): `SearchMusicAction` (Step
  1.8.3) is the only caller, invoked via `PostController::musicSearch` (Step 1.8.4). Instagram never
  gets a real vendor lookup — Upload-Post has no Instagram audio-search endpoint at all — so
  Instagram music selection stays hand-entered (`Post::music['name']`) until/unless a separate
  Instagram-specific provider (e.g. against Meta's own Graph API) is added; revisit if the plan
  needs real Instagram audio search. `find()` has no dedicated vendor endpoint either — it
  re-runs the (unfiltered, i.e. trending-chart) search and matches `id` locally, so it can only
  find a track that's currently in the cached trending charts for the default `country_code`/
  `date_range` (`US`/`7DAY`), same limitation `search()` already has per Upload-Post's own docs.
- The exact shape of Upload-Post's "profile already exists" response (status code/`error_code`/`error`
  text) for `POST /uploadposts/users` wasn't available in this repo; `ensureProfileExists()` matches
  any response whose `error_code` or `error`/body text contains "exist" (case-insensitive) — revisit
  against the real API docs/sandbox if this proves too loose or too strict.

## Changelog
- 2026-10-21: Step M-3.1 — confirmed targets record `published_at`, `vendor_request_id`, `platform_post_id`, `post_url` (webhook + poll); registry `account_metrics` / `post_metrics`; analytics calls via the new `EngagementSource` contract.
- 2026-10-20: Phase 18 (grid planner) — grid sequences: `PlanGridScheduleAction` dates tiles in publish order (last position first) and writes each post's Instagram target through `UpdatePostAction`, so vendor-held channels are recalled and re-sent; `GridSequenceValidator` enforces `grids.min_gap_minutes` (default 10) as a warning; "Schedule approved posts" moves only approved posts, each through its own checklist; "Hold the rest" (`HoldGridPostsAction`) moves later scheduled grid posts back to `approved` when an earlier one fails or leaves the schedule (`GridOrderRiskService`). See `grid-planner.md`.
- 2026-10-19: Step M-0.2 — vendor capability audit: analytics / comments per platform with the source
  each MVP phase uses, Meta scope needs, Upload-Post pricing and margin notes; stale gaps resolved.
- 2026-10-20: Phase M-2 — capability registry (`config/platforms.php`, `PlatformCapabilities`),
  per-channel captions / first comment / link / alt text, explicit formats + covers, media probing,
  vendor cancel + recall/resubmit (`VendorScheduleService`), webhook auto-registration, five new
  networks behind rollout flags. Tests: `PlatformCapabilitiesTest`, `ChannelOverridesTest`,
  `PostFormatsTest`, `VendorHeldPostsTest`, `NewNetworksPublishTest`, settings webhook tests.
- 2026-09-16: Step 1.1 — added `App\Contracts\PublishAdapter`, `App\Support\Publishing\TargetResult`,
  and the default `App\Services\Publishing\NullPublishAdapter` binding, with a unit test covering the
  contract. Contract-only; no vendor call or UI yet.
- 2026-09-16: Step 1.2 — added `App\Services\Publishing\UploadPostAdapter` implementing `publish()`'s
  per-platform field mapping against Upload-Post (common fields + Instagram location/media_type/audio,
  TikTok music/location) via `Http`, authenticated per-org via `upload_post_key`; bound as the default
  `PublishAdapter` in `AppServiceProvider::register()`, replacing `NullPublishAdapter`. Added
  `config('services.upload_post.base_url')`. `connectAccount`/`handleCallback`/`cancel` remain interim
  stubs (Steps 1.3/1.5); `health()` makes a real request but isn't called by anything yet. Covered by
  `tests/Unit/Services/Publishing/UploadPostAdapterTest.php` (mocked via `Http::fake()`).
- 2026-09-16: Step 1.3 — implemented `UploadPostAdapter::connectAccount()`/`handleCallback()` against
  Upload-Post's `generate-jwt` endpoint; added `SocialAccountController::connect`/`callback`, the
  `App\Actions\SocialAccounts\ConnectSocialAccountAction`, the `social-accounts.connect` (authed POST)
  and `social-accounts.callback` (public GET) routes, and a "Connect" button on the client show page.
  Covered by new tests in `UploadPostAdapterTest`/`SocialAccountControllerTest`. See
  `social-accounts.md` for the frontend/policy details.
- 2026-09-16: Bugfix — `UploadPostAdapter::client()` was authenticating with `Http::withToken()`
  (`Authorization: Bearer <key>`), but Upload-Post's API requires `Authorization: Apikey <key>`. This
  caused every request (`connectAccount()`, `publish()`, `health()`) to be rejected with a misleading
  `401 Invalid or expired token` even with a valid, correctly-configured `upload_post_key`. Fixed by
  sending the header manually via `withHeaders()`. Updated the `Authorization` header assertions in
  `UploadPostAdapterTest`.
- 2026-09-16: Bugfix — `connectAccount()` called `generate-jwt` directly without ever creating the
  Upload-Post profile first, so Upload-Post rejected the request (and any later `publish()`/`schedule`
  call reusing the same `username`) with `PROFILE_NOT_FOUND`. Added `ensureProfileExists()`, which
  calls `POST /uploadposts/users` before `generate-jwt` and treats an "already exists" response as
  success. Covered by new tests in `UploadPostAdapterTest`/`SocialAccountControllerTest`.
- 2026-09-16: Bugfix — `SocialAccountController::connect` used to `Inertia::location($url)` the
  user's current tab straight to Upload-Post's hosted connect page, which (a) took over the user's
  Graphy tab with no easy way back and (b) never updated `connectionStatus` in the UI until a manual
  reload, since nothing told the original tab the callback had happened. Fixed by having `connect()`
  return the vendor URL as JSON instead of redirecting, and having `resources/js/pages/clients/show.tsx`
  open it in a separate popup/tab; the callback route now renders a small standalone
  `social-accounts.connected` view that `postMessage`s a confirmation back to the opener and
  auto-closes, with a "popup closed" polling fallback in the frontend. Updated
  `SocialAccountControllerTest`'s connect/callback assertions accordingly.
- 2026-09-16: Bugfix — `handleConnect`'s failure path rendered the vendor's/network's error message as
  plain red text on the client show page instead of using the app's existing Sonner toaster (used
  everywhere else via `useFlashToast`/`<Toaster />`). Since the connect JSON error doesn't go through a
  server-side flash (it's a direct `fetch()` response, not an Inertia visit), `handleConnect` now calls
  `toast.error(...)` from `sonner` directly on failure instead of setting a `connectError` state that
  rendered a `<p>`. No backend change needed.
- 2026-09-19: Step 1.4 — added `App\Jobs\PublishPostJob`, dispatched by
  `TransitionPostStatusAction` whenever a post is transitioned to `scheduled` (delayed until the
  earliest target's `scheduled_at_utc`). The job re-checks the post is still `scheduled`, moves it
  to `publishing`, calls `PublishAdapter::publish()`, then writes each `TargetResult` onto its
  `PostTarget` via three new columns (`status`/`external_post_id`/`error`, migration
  `2026_09_19_150000_add_publish_result_columns_to_post_targets_table`) before moving the post to
  `published`/`failed`. No new route: reused the existing checklist-gated `scheduled` transition
  from Step 0.10 instead of a separate `/posts/:id/schedule` endpoint (see Known Gaps). Covered by
  `tests/Unit/Jobs/PublishPostJobTest.php` and a new assertion in
  `PostTransitionControllerTest::test_owner_can_move_a_post_through_the_valid_transition_chain`.
  The editor's read-only target list and `PostController`'s editor payload now also surface each
  target's publish `status`/`error`.
- 2026-09-19: `PublishPostJob` now sends `App\Notifications\PostPublishFailed` to every org
  recipient (via `App\Services\PostNotificationService`) whenever a post ends up `failed` — see
  `.junie/modules/notifications.md`.
- 2026-09-20: Bugfix — `UploadPostAdapter::publish()` always POSTed to `/uploadposts/schedule`, which
  doesn't exist in Upload-Post's real API and returned `405 Method Not Allowed` for every publish
  attempt (surfaced as `PublishPostJob: post publish failed for one or more targets`). Per Upload-Post's
  real API reference, there is no generic publish endpoint — requests must go to `/upload` (video),
  `/upload_photos` (photos), or `/upload_text` (text-only). Added `resolveUploadEndpoint()` to pick the
  right endpoint from the post's assets, and changed `buildFields()` to send a single `video` URL or a
  `photos` URL array (matching each endpoint's real field names) instead of the non-existent
  `media_urls`. Updated `UploadPostAdapterTest`'s faked URLs/field assertions accordingly.
- 2026-09-20: Bugfix — publishing a post with a locally-uploaded photo/video asset was rejected by
  the vendor (`{"success":false,"message":"Photo files or URLs are required"}` for photos,
  `"Video URL is not allowed."` for video) because `buildFields()` sent the asset's `url` column as
  a plain field, and that URL is generated from `APP_URL` — a local-only dev domain (e.g.
  `graphy-planner.test`) that Upload-Post's servers can never reach/download from, so the vendor
  effectively saw no usable media. Fixed by adding `UploadPostAdapter::attachLocalMediaFiles()`:
  any video/image asset that has a local `disk`/`path` we control is now attached as a real
  multipart file (`video`/`photos[]`) with its actual bytes read via `Storage::disk(...)->get(...)`,
  removing the corresponding `video`/`photos` URL entry from the request so it isn't also sent.
  Assets with no local disk/path (e.g. genuinely external links) still fall back to sending their
  `url` as before. Covered by
  `UploadPostAdapterTest::test_a_locally_stored_photo_asset_is_attached_as_a_file_instead_of_a_url`.
- 2026-09-20: Bugfix — every `UploadPostAdapter` publish request (`/upload`, `/upload_photos`)
  was rejected with `{"success":false,"message":"Username required in form data"}` (seen live for
  Instagram, but affecting every platform/target) because Laravel's `Http` client defaults to
  `asJson()`, while Upload-Post's upload endpoints require `multipart/form-data` with a scalar
  `user` field and repeated `platform[]`/`photos[]` parts (per the vendor's documented `-F`
  examples) — sending JSON meant the vendor never saw any field, including `user`. Fixed by
  sending those two endpoints via `Http::asMultipart()` with a new `toMultipartFields()` helper
  that expands array-valued fields (`platform`, `photos`, `hashtags`) into repeated `name[]`
  parts; `/upload_text` (no file-ish fields, documented as JSON) and the profile/connect endpoints
  are unaffected. Updated `UploadPostAdapterTest`'s fakes/assertions to read the multipart part
  list instead of a plain JSON body.
- 2026-09-20: Bugfix — re-queuing a `failed` post (`failed → scheduled`) left every `PostTarget`'s
  stale `status`/`external_post_id`/`error` from the previous failed attempt in place until
  `PublishPostJob` ran again, which made a re-queue look like it hadn't done anything (the target
  still showed `failed` next to the account right after clicking "Move to Scheduled"). Also, the
  vendor error was only ever shown on the target row next to the social account, not on the
  activity log. Fixed both: `TransitionPostStatusAction` now resets every target to `pending`
  (clearing `external_post_id`/`error`) whenever a post moves `failed → scheduled`, and
  `PublishPostJob` now passes a note summarizing each failed target's platform/handle/error as the
  `$comment` on the `failed` transition, so it's recorded on the `PostActivityLog` entry instead.
  Removed the per-target error text from the read-only target list in `posts/edit.tsx` (the
  `status` badge is kept). Covered by
  `PostTransitionControllerTest::test_a_failed_post_can_be_moved_back_to_scheduled_and_is_requeued_with_a_clean_target`
  and a new assertion in `PublishPostJobTest`.
- 2026-09-20: Step 1.5 — added `checkStatus(SocialAccount, string $externalPostId): ?TargetResult`
  to `PublishAdapter` (implemented in `NullPublishAdapter` as a no-op `null`, and in
  `UploadPostAdapter` against a guessed `GET /uploadposts/posts/{externalPostId}` status-lookup
  endpoint); added `App\Actions\Publishing\SyncPublishStatusAction` (finalizes a `pending`
  `PostTarget` to `published`/`failed` and transitions its post once every target has resolved),
  a signature-verified `POST /webhooks/upload-post` route/`UploadPostWebhookController` (primary
  sync path), and `App\Actions\Publishing\PollPendingPublishStatusesAction` +
  `PollPendingPublishStatusesCommand` (`publishing:poll-pending-statuses`, scheduled every 5
  minutes) as the fallback poll. Added `config('services.upload_post.webhook_secret')`
  (`UPLOAD_POST_WEBHOOK_SECRET`) and excluded `webhooks/*` from CSRF verification in
  `bootstrap/app.php`. Deliberately left `PublishPostJob`'s existing synchronous
  published/failed marking unchanged (see Known Gaps) rather than risk regressing Step 1.4's
  tested behavior without a confirmed real vendor contract; the new webhook/poll machinery is
  fully tested against a `PostTarget` left `pending` on a `publishing` post. Covered by
  `SyncPublishStatusActionTest`, `PollPendingPublishStatusesActionTest`,
  `UploadPostWebhookControllerTest`, and new `checkStatus()` assertions in
  `NullPublishAdapterTest`/`UploadPostAdapterTest`.
- 2026-09-20: Step 1.5 (revised, against real Upload-Post docs) — replaced every guessed
  endpoint/payload/signature shape from the initial Step 1.5 pass with the real, documented
  contract fetched from https://docs.upload-post.com/api/webhooks and
  https://docs.upload-post.com/api/upload-status:
  - `UploadPostAdapter::checkStatus()` now calls the real `GET /uploadposts/status?request_id=
    &job_id=` endpoint (both params sent, since the caller doesn't track which flavor of id it
    captured), resolves only on a top-level `completed`/`failed` status, and prefers a matching
    entry in the response's per-platform `results[]` over the top-level status/message when one
    exists (a single job can report several platforms' outcomes).
  - `publishToTarget()` now captures `job_id` (falling back to `request_id`/`id`) as
    `externalPostId`, since a scheduled publish returns `job_id`.
  - `UploadPostWebhookController` now only handles the `upload_completed` event, reads `job_id`/
    `platform`/`result.success`/`result.error` (not `request_id`/`status`/`error`), matches the
    `PostTarget` on both `job_id` *and* the target's account platform (one `job_id` fans out to
    one event per platform), and verifies `X-Upload-Post-Signature: sha256=<hex>` against
    `HMAC_SHA256(secret, "{X-Upload-Post-Timestamp}.{raw body}")` with a 5-minute replay window —
    not the previously-guessed plain `HMAC_SHA256(secret, raw body)`.
  - Moved the webhook signing secret from a single `config('services.upload_post.webhook_secret')`
    (env `UPLOAD_POST_WEBHOOK_SECRET`) to a new encrypted `organizations.upload_post_webhook_secret`
    column (migration `2026_09_20_181800_add_upload_post_webhook_secret_to_organizations_table`),
    since Upload-Post issues one secret per account — resolved from the matched target's
    organization, alongside a new field on the Organization settings page/form. No automated way
    to populate it yet (see Known Gaps).
  - Updated `UploadPostAdapterTest`'s `checkStatus()` cases and rewrote
    `UploadPostWebhookControllerTest` end-to-end for the real payload/signature shape, adding a
    stale-timestamp-rejection and an unrelated-event-is-a-no-op test.
- 2026-09-20: Bugfix — hashtags never showed up on published Instagram posts even though the
  caption and media published correctly. Per Upload-Post's real API reference
  (https://docs.upload-post.com/api/reference), there is no generic `hashtags` upload parameter —
  it only exists on the unrelated TikTok hashtag-suggestions endpoint — so the plain
  `hashtags`/`hashtags[]` field `buildFields()` sends for every platform was silently ignored by
  the vendor for Instagram. Instagram captions carry hashtags as literal `#tag` text instead, so
  `applyInstagramFields()` now appends the post's hashtags (re-prefixed with `#`, since
  `Post::booted()` stores them bare) to the caption sent via Upload-Post's Instagram-specific
  `instagram_title` field, leaving the generic `title` field (used by every other platform)
  unchanged. Covered by
  `UploadPostAdapterTest::test_instagram_publish_appends_hashtags_to_the_instagram_caption`.
- 2026-09-20: Bugfix — a post scheduled with Upload-Post could show `published` in Graphy well
  before the vendor actually delivered it, because `PublishPostJob` treated a successful `publish()`
  response (`TargetResult::ok`) as final proof of delivery and immediately marked the target
  `published` and transitioned the post to `published`. In reality `ok: true` only means the vendor
  *accepted* the request (returning a `job_id`/`request_id` to track it) — for a future-dated
  `scheduled_date` the actual post to the social platform can happen much later. Fixed by having
  `PublishPostJob` leave an accepted target `pending` and the post `publishing`, so the post is only
  finalized to `published`/`failed` once the already-built `SyncPublishStatusAction` webhook/poll
  machinery (Step 1.5) confirms the vendor's real outcome — the same machinery a rejected-outright
  target still bypasses (moves the post straight to `failed`, unchanged). Updated
  `PublishPostJobTest`'s success-case assertions accordingly; no other test needed changes since the
  webhook/poll tests already expected this `pending`/`publishing` starting state.
- 2026-09-22: Bugfix — hashtags still weren't showing up on published Instagram posts even after
  the 2026-09-20 fix appended them to `instagram_title`, because Instagram (and, per user reports,
  likely other platforms too) visually truncates captions after a few lines behind a "more" link,
  and the hashtag block appended directly after the caption (`caption\n\n#tag1 #tag2`) was often
  cut off or looked cluttered right under the caption text. `applyInstagramFields()` now separates
  the caption from the hashtag block with the common Instagram "dot-dash" spacer — eight lines
  each containing a single `-` — producing `caption\n-\n-\n-\n-\n-\n-\n-\n#tag1 #tag2`, which
  pushes the hashtags below the "more" fold instead of cluttering the visible caption. Updated
  `UploadPostAdapterTest::test_instagram_publish_appends_hashtags_to_the_instagram_caption`'s
  expected `instagram_title` value accordingly.
- 2026-09-22: Bugfix (follow-up) — user reported hashtags were still missing, and confirmed
  (against Upload-Post's real docs — the `[platform]_title` per-platform title-override
  convention documented at https://docs.upload-post.com/api/upload-video#platform-specific-titles)
  that the previous fix only ever covered Instagram (`instagram_title`): TikTok, Facebook, and
  LinkedIn posts never got hashtags at all, since Upload-Post has no generic `hashtags` upload
  parameter for *any* platform (only the unrelated TikTok hashtag-suggestions endpoint has a
  `hashtags` field) — those three platforms were still sending only the plain `title` (no
  hashtags) with no override. The Instagram-only hashtag/spacer logic in
  `applyInstagramFields()` was extracted into a new `applyHashtagCaptionOverride()`, now called for
  every platform from `buildFields()`, which appends the caption + dot-dash spacer + hashtags to
  that platform's own override field (`instagram_title`/`tiktok_title`/`facebook_title`/
  `linkedin_title`) instead of just Instagram's. The generic `title` field sent to every platform
  is left unmodified (still just the caption), since it's only a documented fallback for platforms
  with no override sent. Added
  `UploadPostAdapterTest::test_tiktok_facebook_and_linkedin_publish_append_hashtags_to_their_own_title_override`
  covering all three previously-unfixed platforms.
- 2026-09-22: Bugfix (2nd follow-up) — user reported hashtags were *still* not reaching Instagram
  and pointed at Upload-Post's docs mentioning a `description` parameter for the caption/hashtags.
  Re-verified against the real per-platform tables in
  https://docs.upload-post.com/api/upload-video and https://docs.upload-post.com/api/upload-photo:
  Instagram's own docs explicitly say `instagram_title` **is** the caption and "the global
  `description` field is ignored for Instagram uploads" — so the existing `instagram_title`
  approach is correct for Instagram and switching it to `description` would have broken it.
  However, the same docs show that for **TikTok, Facebook, and LinkedIn** the field the vendor
  actually renders as the post's visible body text is the generic `description`
  (`tiktok_description`/`facebook_description`/`linkedin_description` aliases; Facebook's own photo
  docs even document the resolution order `facebook_description` > `description` > `title`), while
  `[platform]_title`/`title` is only a secondary media title — meaning the previous fix's
  `[platform]_title`-only override left hashtags off the actual visible caption on those three
  platforms. `applyHashtagCaptionOverride()` now also sends the generic `description` field (same
  caption+hashtags text) for TikTok/Facebook/LinkedIn, while still deliberately omitting it for
  Instagram. Updated `UploadPostAdapterTest`'s Instagram test to assert `description` is *not*
  sent, and the TikTok/Facebook/LinkedIn test to assert `description` *is* sent alongside the
  title override.
- 2026-09-22: Bugfix (3rd follow-up) — user reported hashtags were *still* missing on Instagram
  even with the `instagram_title` + generic `description` combination, and pointed at Upload-Post's
  own docs example that appends hashtags directly inline in the plain `title` field itself
  (`-F 'title="Check out our new launch! #tech #startup #launch"'`, per
  https://docs.upload-post.com/api/reference), asking to try that field instead.
  `applyHashtagCaptionOverride()` now also overwrites the generic `title` field (previously left as
  the bare caption) with the same caption + dot-dash spacer + hashtags text, for every platform, in
  addition to the platform-specific override (`instagram_title`/etc.) and `description` (where
  applicable) already being sent — so the hashtag text now reaches the vendor via all three
  documented channels rather than relying on a single override field alone. Updated
  `UploadPostAdapterTest`'s Instagram/TikTok/Facebook/LinkedIn hashtag regression tests, and the
  existing `test_linkedin_publish_sends_only_caption_media_and_schedule` test (which also has
  hashtags set), to assert `title` itself now carries the caption+hashtags text.
- 2026-09-23: Step 1.6 — wired up `PublishAdapter::health()` (already implemented in
  `UploadPostAdapter`/`NullPublishAdapter` since Step 1.2, but never called): added
  `App\Actions\SocialAccounts\CheckSocialAccountHealthAction` (flips one `connected` account to
  `token_expired` when `health()` fails) and `CheckAllSocialAccountsHealthAction` (sweeps every
  `connected` account), run via the new `social-accounts:check-health` command scheduled hourly —
  mirrors Step 1.5's `publishing:poll-pending-statuses` pattern. `ClientHealthService` gained a
  matching Red trigger for any client with a `token_expired` account. See `social-accounts.md` and
  `clients.md` for the full details and tests.
- 2026-09-23: Step 1.7 — added `App\Enums\PlatformRolloutStage` (Instagram feed, Instagram
  Reels, TikTok, Facebook, LinkedIn, in the plan's literal rollout order),
  `config('publishing.rollout')` (one env-backed boolean flag per stage, all defaulting to
  `true` since every stage already has a passing publish test from Steps 1.2-1.6), and
  `App\Services\Publishing\PlatformRolloutGate` (resolves a target's stage and checks its flag).
  `UploadPostAdapter::publishToTarget()` now consults the gate before any vendor call, returning
  a failed `TargetResult` for a disabled stage without an HTTP request. Covered by
  `PlatformRolloutGateTest` and two new `UploadPostAdapterTest` cases (disabled stage skips the
  vendor; enabled stage calls it).
- 2026-09-23: Step 1.8.1 — added `App\Contracts\MusicProvider` (`search(string $query, Platform
  $platform): Collection<MusicTrack>` and `find(string $id, Platform $platform): ?MusicTrack`) and
  `App\Support\Publishing\MusicTrack` (readonly DTO: `id`, `name`, `artist`, `previewUrl`,
  `platform`), mirroring the `PublishAdapter`/`TargetResult` pattern so the sound-library vendor
  stays swappable/mockable. Contract-only step — no default binding, adapter, Action, or UI yet
  (those land in Steps 1.8.2-1.8.4). Covered by `MusicProviderTest` (anonymous implementation
  satisfies the interface) and `MusicTrackTest` (DTO field/nullability checks).
- 2026-09-23: Step 1.8.2 — added `App\Services\Publishing\UploadPostMusicProvider`, bound as the
  default `MusicProvider` in `AppServiceProvider::register()`. Confirmed against Upload-Post's
  real API reference that only TikTok has a real music/sound endpoint
  (`GET /uploadposts/tiktok/music/search`) — Instagram has no equivalent anywhere in Upload-Post's
  API, so `search()`/`find()` return an empty-collection/`null` for every other platform without
  calling the vendor. Since the Step 1.8.1 contract carries no `SocialAccount`/org parameter, the
  org is resolved from the authenticated user (`Auth::user()->organization`, matching how every
  controller in this app resolves it) and the vendor's required `profile` param from that org's
  first connected TikTok `SocialAccount`; `find()` re-uses the same search endpoint (unfiltered
  query) and matches the vendor's `id` locally, since Upload-Post has no dedicated get-by-id
  endpoint. Covered by `UploadPostMusicProviderTest` (default binding, TikTok search/find against
  a faked vendor response, unsupported-platform/no-connected-account/vendor-rejection all return
  empty/`null` without further vendor calls where applicable).
- 2026-09-23: Step 1.8.3 — added `App\Actions\Publishing\SearchMusicAction`, a thin Action
  constructor-injected with `App\Contracts\MusicProvider` and delegating entirely to
  `MusicProvider::search()`; no `DB::transaction()` (single read call), logs the search start/result
  count (platform + count only, never the raw query text). No controller/route calls it yet — that
  lands with Step 1.8.4's editor music picker. Covered by `SearchMusicActionTest` (delegates to a
  mocked `MusicProvider`, returns an empty collection when nothing matches).
- 2026-09-23: Step 1.8.4 — added `PostController::musicSearch` (`GET posts/{post}/music-search`,
  mirrored under `preview.`), the first caller of `SearchMusicAction`: authorizes `view`, validates
  `query`/`platform`, short-circuits to `{tracks: []}` for any platform other than Instagram/TikTok,
  and otherwise returns each `MusicTrack` as `{id, name, artist, preview_url}` JSON. Replaced the
  `posts/edit` editor's locked "Not available yet" Music text input with a live search box (query
  input + Search button, calling the endpoint via a plain `fetch()` mirroring the `clients/show`
  connect-flow pattern) shown only while a selected target account is Instagram/TikTok (with a
  platform toggle when both are selected); picking a result writes `{id, name}` into hidden
  `music[id]`/`music[name]` form inputs, the same shape `UploadPostAdapter` already reads. Covered
  by two new `PostEditorControllerTest` cases. See `posts.md` for the editor-side frontend details.
- 2026-09-23: Step 1.8.5 — end-to-end verification only, no production code changed:
  `applyInstagramFields()`/`applyTikTokFields()` already read `Post::music['id']` verbatim into
  `audio_id`/`tiktok_music_id` since Step 1.2, so a real Step 1.8.4 music selection already carries
  through unchanged. Added `tests/Feature/MusicSelectionAcceptanceTest.php`: two §12-style
  acceptance scenarios per platform that call `PostController::musicSearch` against a mocked
  `MusicProvider` track, save the returned `{id, name}` onto the post via `PostController::update`
  exactly as the editor does, then run the real `UploadPostAdapter::publish()` against a faked
  Upload-Post response and assert the track's id lands on `audio_id` (Instagram) /
  `tiktok_music_id` (TikTok) in the request actually sent to the vendor. Closes out Step 1.8.
- 2026-09-23: Step 1.8.6 — bugfix: user reported the editor's music search "just returns an empty
  array of tracks" for both Instagram and TikTok. Reproduced against the *real* Upload-Post API
  (using a live org `upload_post_key`): the HTTP integration itself is correct (confirmed the
  vendor's actual `PROFILE_NOT_FOUND`/`tiktok_reconnect_required` responses round-trip through
  `fetchTracks()` exactly as expected) — Instagram has no vendor endpoint at all (unfixable without
  a separate integration, unchanged), and TikTok was silently swallowing every failure (no
  connected account, vendor request failure, vendor rejection) into an empty `Collection` with only
  a server-side log, so the editor showed the exact same "no results" for a genuinely fixable
  connection problem as for a normal "nothing matched" search. `UploadPostMusicProvider::search()`/
  `find()` now throw the new `App\Exceptions\Publishing\MusicProviderUnavailableException` (with a
  user-facing message, including a dedicated one for `tiktok_reconnect_required`) instead of
  returning empty in those cases; `PostController::musicSearch` catches it and responds
  `{tracks: [], error: message}`; `resources/js/pages/posts/edit.tsx` now shows that `error` via
  the existing `musicSearchError` UI instead of just an empty results list. Updated
  `UploadPostMusicProviderTest`'s "no connected account"/"vendor rejects" cases to assert the
  exception instead of an empty collection, added a case for the `tiktok_reconnect_required`
  message, and added `PostEditorControllerTest::test_music_search_surfaces_the_music_provider_unavailable_reason`.
- 2026-09-25: Step 1.9 — real Instagram audio search via Meta's Graph API, replacing the
  Step 1.8.2 empty-result gap for Instagram (Upload-Post has no equivalent endpoint at all).
  1.9.1 added `config('services.facebook')` (a separate Facebook App from Upload-Post,
  `FACEBOOK_CLIENT_ID`/`_CLIENT_SECRET`/`_REDIRECT_URI`/`_GRAPH_BASE_URL`/`_GRAPH_VERSION`). 1.9.2
  added `meta_access_token`/`meta_access_token_expires_at`/`meta_instagram_user_id` to
  `social_accounts` (encrypted token cast). 1.9.3 added the additive
  `App\Http\Controllers\Auth\FacebookInstagramConnectController` (`redirect()`/`callback()`, a
  thin `Http`-based OAuth exchange — no Socialite dependency installed) and
  `App\Actions\SocialAccounts\ConnectMetaAudioAccessAction` to persist the grant. 1.9.4 added
  `App\Services\Publishing\MetaGraphMusicProvider`, now the default `MusicProvider` binding: real
  Graph `GET /ig_audio` for Instagram, unchanged delegation to `UploadPostMusicProvider` for
  TikTok, and a `meta_not_connected`-coded `MusicProviderUnavailableException` when the org's
  Instagram account hasn't completed the 1.9.3 flow. 1.9.5 added an `error_code` field to
  `PostController::musicSearch`'s JSON response and, on `posts/edit`, an inline "Connect Instagram
  for music search" link (to the 1.9.3 connect route for the active Instagram target account)
  shown only for that specific error code. 1.9.6 added
  `tests/Feature/MetaAudioSelectionAcceptanceTest.php`, repeating the Step 1.8.5 §12 pattern with
  the real `MetaGraphMusicProvider` to prove a selected Instagram track's id still reaches
  `UploadPostAdapter`'s `audio_id` field unchanged. See `social-accounts.md` for the connect-flow
  model/route details.
- 2026-10-17: Step 16.13 AI disclosure. `PublishAdapter::publish()` documents that adapters must label AI media; `UploadPostAdapter` now sends Upload-Post's `is_ai_generated=true` alias (mapped by the vendor to TikTok `is_aigc`, Instagram, Facebook Reels) whenever `Post::hasAiGeneratedMedia()`, replacing the never-sent `tiktok_is_ai_generated` placeholder. Per-platform support table above.
- 2026-10-18: Steps I-1.2 / I-1.3 / I-1.4 — publish pipeline hardening. `posts.publish_dispatch_id`
  makes stale delayed jobs no-op and lets scheduled posts be rescheduled; `PublishPostJob` is
  unique, retried, row-locked and fans out `PublishPostTargetJob`s in a batch; new
  `partially_published` status, `ResolvePostPublishOutcomeAction`, `RetryFailedPostTargetsAction` +
  route; `post_targets.publish_attempt_id`/`publish_requested_at` for at-most-once sends;
  `VendorHttp` timeouts/retries on every vendor call; delivery-confirmation timeout in the poll;
  transitions lock and re-check, side effects after commit, all mail/notifications queued.
- 2026-10-18: Step I-1.7 — the Upload-Post webhook is throttled per IP (`throttle:webhooks`, 120/min). See `ai.md` for the full limiter table.
- 2026-10-18: Step I-1.8 — `publishing:poll-pending-statuses` and `social-accounts:check-health` are
  overlap-protected and single-server, and walk rows with `lazyById()`
  (`CheckAllSocialAccountsHealthAction` used offset-paged `each()` while flipping
  `connection_status`, which could skip accounts). See `super-admin.md` for the heartbeat tile.
- 2026-10-19: `UploadPostAdapter` crops local images to the post's per-channel format before
  attaching them (`PublishImageCropper`, injected with a default like `PlatformRolloutGate`); AI
  images bound for LinkedIn are never re-encoded. Tests: `UploadPostAdapterTest` (crop to 1:1 around
  the focus point, LinkedIn AI original untouched, `original` untouched) and
  `tests/Unit/Services/Media/PublishImageCropperTest.php`. See `posts.md` for the format rules.
- 2026-10-20: Bugfix — scheduling a post didn't reach Upload-Post until the post's own time
  (`PublishPostJob` was only queued, delayed), and locally no worker ran at all (see `ops.md`).
  Posts are now sent when they're scheduled (`SendScheduledPostToVendorJob`) and stay `scheduled`
  while the vendor holds them; unschedule / move / edit / delete / archive client / suspend or
  delete org cancel the held channels first; the poller no longer times out held channels. Tests:
  `ScheduledPostHandOffTest`.
- 2026-10-20: Step M-1.1: `publish_correlation_id`, trace ids in the publishing jobs and webhook, alert on bad webhook signatures and failed publishing jobs (`ObservabilityTest`).
- 2026-10-21: Bugfix — moving a post to `scheduled` surfaced a per-channel error like
  `instagram (graphystudio.co): Attempted to lazy load [organization] on model
  [App\Models\SocialAccount] but lazy loading is disabled.` `UploadPostAdapter::baseRequest()`
  reads the Upload-Post API key from `$account->organization`, but `PublishPostTargetJob` loads
  the account via `Post::with('targets.socialAccount', …)` (no `.organization`), so the relation
  was unloaded and, with `Model::preventLazyLoading()` on outside production, the access threw;
  the throwable was caught in `publishToTarget()` and returned as that channel's `TargetResult`
  error. Fixed centrally in `baseRequest()` with `$account->loadMissing('organization')` so every
  vendor call (publish/health/status) is safe regardless of how the account was fetched; a no-op
  when callers already eager-load it. Regression test:
  `UploadPostAdapterTest::test_publish_loads_the_organization_when_the_account_relation_is_not_eager_loaded`
  (publishes with a freshly-fetched account whose `organization` relation is confirmed unloaded).
