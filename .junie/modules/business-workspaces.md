# Business & Creator Workspaces

## Purpose
A second kind of workspace for **one business or creator managing their own social media**, with no
agency in between (full scope in `.junie/plans/business-workspaces.md`). The same engine
(publishing, the dual-timezone `PostTarget` model, AI providers, Image Studio, analytics core, asset
store) is reused; the business workspace is a new **shell** (routes, navigation, copy, defaults) plus
a few per-type **contracts** (workflow, entitlements, publish credentials), never a fork of the
domain.

This file covers **Phase B-0** (decisions & groundwork), **Phase B-1** (workspace type & data
model), **Phase B-2** (signup & onboarding), **Phase B-3** (workflow for one person or a small
team), **Phase B-4** (the business app shell), the Phase B-5/B-6 publishing+AI extensions, and
**Phase B-7** (phone-first creation: the installable PWA, the quick post flow, Share-to-Alima and
push reminders). Later phases (plans, teams) extend it. The B-3 post-workflow internals (contract,
Actions, share preview) live in `.junie/modules/posts.md`; this file records the workspace-level
pieces, the review setting and the shell.

## The "primary brand" model (architecture decision — §2 of the plan)
- `client_id` is non-nullable on posts, social accounts, assets, campaigns, daily briefs, brand
  brains, AI rows and image generations, and is the scoping key for analytics, calendar and
  policies. Making it nullable would double every query/policy path (~60 FKs) — rejected.
- Instead: `organizations.type` (`App\Enums\OrganizationType`: `agency` | `business`, default
  `agency`, so every existing org is unchanged). A `business` org owns exactly **one primary
  brand** — an ordinary `clients` row with `is_primary = true`, created with the org. Everything
  downstream still keys off `client_id` and works unchanged.
- One primary brand per org is guaranteed **at the database level** by a unique index on a generated
  column `clients.primary_org_id` (= `org_id` only for the primary row, else NULL; NULLs don't
  collide in a unique index — MySQL's substitute for a partial index). The column is **VIRTUAL** on
  both MySQL 8 and the SQLite test run: `clients.org_id` has an ON DELETE CASCADE foreign key, and
  MySQL forbids a cascading-FK column as the base of a *stored* generated column (it throws the
  misleading error 1215). A virtual column has no such restriction and the unique index gives the
  identical guarantee (verified on MySQL: a second primary row is rejected).
- A request-scoped `App\Services\Workspace\CurrentWorkspace` resolves the org, its type and its
  primary brand once per request. Business controllers never take a `{client}` parameter; they ask
  `CurrentWorkspace` for the brand and call the **same Actions** agency controllers call.
- Agency-only surfaces are blocked for business orgs by middleware **and** policies (defence in
  depth). Behaviour that genuinely differs sits behind contracts resolved per org type, not
  scattered `if ($org->type === ...)` branches.
- The `Client` model keeps its name in code; only the business UI will call it "your brand".

## Resolved open questions (§5 — the two that block Phase B-1)
1. **Naming.** One product ("Alima"), two signups: the agency flow at `/register-organization` and
   the business/creator flow at `/start` (Phase B-2) are two entry points into the same product. No
   separate sub-brand. The internal enum stays `OrganizationType::Business`.
2. **Free tier.** A limited **free tier + paid upgrade** model (e.g. 3 accounts, 30 scheduled
   posts/month, a small AI allowance), not trial-only — so cost controls (Phase B-9.1–9.2) and the
   abuse safeguards (B-13.1) matter from day one. **Payments (Stripe/Cashier, Step B-9.3) are
   deferred** by product decision; limits are still enforced in Actions when B-9 lands.

The other §5 questions (Upload-Post managed mode, vision provider, holiday data, creator platforms,
agency→business downgrade = out of scope, inactive-data retention) are resolved in their own phases.

## Models / Tables
- `Organization` (`organizations`): `type` column (`OrganizationType`, string, not null, default
  `agency`, indexed). Helpers `isBusiness()`/`isAgency()`, scope `ofType()`, relation
  `primaryBrand()` (`HasOne<Client>` filtered on `is_primary`). Default attribute `type = agency` so
  an unsaved org already reads as an agency. **B-2.2:** `onboarding_completed_at` (nullable
  timestamp) — null means "still onboarding"; `needsOnboarding()` is true only for a business org
  with it null. **B-3.3:** `require_post_review` (boolean, default false) — when true a business
  workspace makes posts pass through an internal review step before they can be approved/scheduled
  (`BusinessPostWorkflow` reads it); agency orgs ignore it. **B-5.1:** `publish_mode`
  (`App\Enums\PublishMode`, nullable — an explicit override of the type-based default; null = use
  the default) and `upload_post_profile` (nullable string — the org's isolated managed Upload-Post
  profile username; not a secret). Both resolved only through `App\Contracts\PublishCredentials`.
  **B-8.2:** `insights_digest_opt_out` (boolean, default false) — the Owner's opt-out of the weekly
  Insights email digest (`wantsInsightsDigest()` = business + onboarded + not opted out).
- `MediaKit` (`media_kits`) — **B-8.4:** one per primary brand (`client_id` unique; `org_id` FK +
  index). `bio` (nullable text, the editable blurb), `share_token` (nullable unique random slug — the
  opt-in public link; null = private) and `shared_at`. `isPublic()` = token set. Not org-scoped by a
  global scope (like `CampaignShareLink`): the owner reaches it through the workspace brand, the
  public route looks it up by token.
- `Client` (`clients`): `is_primary` boolean (default false) and read-only generated
  `primary_org_id` with a unique index (the one-primary-per-org guarantee). Cast `is_primary` to
  bool; added to `#[Fillable]`. **B-2.1/B-2.3:** `brand_kind` (`App\Enums\BrandKind`, nullable — set
  by the business signup) and `location` (nullable string — city/country, collected in onboarding,
  used by the local/seasonal idea engine in B-6.1). Both nullable so agency clients and super-admin
  onboarding leave them unset. **B-4.5:** `posting_goal` (nullable JSON) — the richer weekly goal
  (`{posts_per_week, preferred_days}`, ISO weekday numbers), distinct from the single-integer
  `target_posts_per_week` (a health input). Read through the `App\Support\Workspace\PostingGoal`
  value object via `Client::postingGoal()`.

- `PushSubscription` (`push_subscriptions`) — **B-7.4:** one browser push subscription per user
  (`user_id` cascade FK, indexed). `endpoint` (text) + `endpoint_hash` (sha-256, unique — endpoints
  are long, so the hash is the upsert key), `public_key` (p256dh), `auth_token`, `content_encoding`.
  `User::pushSubscriptions()`. Deleting the row is the opt-out.
- `PostTarget` (`post_targets`) — **B-7.4:** `push_reminder_sent_at` (nullable timestamp), the dedup
  stamp for the "goes out soon" push, mirroring `reminder_sent_at` (the 3-day email reminder).

## Enums
- `App\Enums\OrganizationType` — `Agency` | `Business`, with `label()`.
- `App\Contracts\PushDeliveryResult` — **B-7.4:** `Delivered` | `Failed` | `Gone` | `Skipped`, the
  outcome of one push send (a `Gone` subscription is pruned).
- `App\Enums\PublishMode` — **B-5.1:** `Byo` | `Managed`, with `label()`/`isManaged()`. How an org's
  Upload-Post credentials are sourced; business orgs are always managed.
- `App\Enums\PublishFailureReason` — **B-5.3:** a plain-language cause for a publish failure
  (`classify()` + `message()`/`fix()`/`isRetryable()`/`needsReconnect()`/`describe()`). See
  `.junie/modules/publishing.md`.
- `App\Enums\BrandKind` — `Business` | `Creator`, with `label()` and `tagline()` (the signup "I'm
  a…" choice, stored on the primary brand).
- `App\Enums\Role` (extended) — `labelFor(OrganizationType)` (business labels: Strategist→"Manager",
  Designer→"Creator"); `assignableFor(OrganizationType)` (business excludes `ClientReviewer` **and**
  `SuperAdmin`; agency unchanged = every role except `ClientReviewer`).

## Actions / Services / Contracts
- `App\Actions\Workspace\CreatePrimaryBrandAction` — creates the primary `Client` (active,
  `is_primary`, optional `brand_kind`) and an empty `BrandBrain` in one transaction; the only
  supported way to create a primary brand. Logs `org_id`/`client_id`/`brand_kind`.
- `App\Actions\Organizations\RegisterOrganizationAction` — **B-2.1:** now workspace-type-aware. Takes
  optional `type` (default `agency`, so existing callers are unchanged) and `brand_kind`; for a
  `business` signup it creates the primary brand via `CreatePrimaryBrandAction` **inside the same
  transaction**, so an org/owner/brand trio is all-or-nothing.
- `App\Services\Workspace\CurrentWorkspace` (bound `scoped`) — `organization()`, `type()`,
  `isBusiness()`/`isAgency()`, `brand()` (cached per request; throws
  `WorkspaceMisconfiguredException` if a business org has no primary brand), `brandId()`.
- `App\Services\Workspace\OnboardingProgress` — **B-2.2:** the business-onboarding checklist computed
  from real data (not stored flags): `brand` (what-you-do / voice set), `accounts` (≥1 connected),
  `first_post` (≥1 scheduled/published), `team` (optional). **CG-1.4** adds an optional `grid`
  ("Plan your grid") item, included only when the brand has a connected Instagram account and ticked
  by any grid plan. Returns `items`, `requiredComplete`, `completedAt`. A handful of indexed existence
  checks scoped to the primary brand.
- `App\Actions\AI\DraftBrandProfileAction` — **B-2.4:** the brand-profile AI quick-start. Summarises
  the brand's website (via `WebsiteSummarizer`, SSRF-guarded), asks the `AiProvider` to draft
  voice/audience/offer/pillars, records the call in `ai_usage_logs`, and returns an
  `AiBrandProfileDraft`. **Nothing is persisted** — acceptance goes through `UpdateBrandBrainAction`.
  A disabled/failed provider yields an empty draft (the wizard shows an editable blank form).
- `App\Contracts\WebsiteSummarizer` (bound to `App\Services\Web\HttpWebsiteSummarizer`) — fetches a
  public page and returns readable text, or `null`. SSRF guard: http(s) only, rejects any host that
  resolves to a private/loopback/link-local/reserved IP (and a host that resolves to nothing), does
  not follow redirects, time- and size-capped, HTML/text only; never throws.
- `App\Contracts\AiProvider::draftBrandProfile(BrandProfileInput): AiBrandProfileDraft` — **B-2.4:**
  added to the contract; implemented by `DeepSeekProvider` (strict-JSON prompt, no `Client` context)
  and `NullAiProvider` (empty draft). `App\Support\AI\BrandProfileInput` / `AiBrandProfileDraft` are
  the DTOs; the draft carries `succeeded`/`provider`/`model` for metering and the wizard fallback.
- `App\Exceptions\Workspace\WorkspaceMisconfiguredException` — business-org invariant violation.
- `App\Actions\Workspace\UpdateWorkspaceSettingsAction` — **B-3.3:** writes the workspace's own
  settings (currently `require_post_review`), logging the change. Owner-only via the request/policy.
- **Phase B-4 shell:**
  - `App\Actions\Business\GetBusinessDashboardAction` — the "Today" dashboard data, counted/bounded
    SQL-side (I-2.2), scoped to the primary brand: **upNext** (next 5 scheduled targets),
    **gaps** (via `PostingGoalGapService`), **needsYou** (reuses `GetNeedsAttentionItemsAction` +
    stale drafts; **CG-1.1** adds unanswered-comment rows and **CG-1.4** grid-order-at-risk rows,
    the latter re-verified through `GridOrderRiskService`), **thisWeek** (publishing consistency).
    `ideaOfTheDay` is null until B-6.1.
  - `App\Actions\Business\GetBusinessInsightsAction` — single-brand publishing consistency (weekly
    posted counts, streak, by-platform); reach/engagement land with B-8 (`hasMetrics: false`).
  - `App\Services\Business\PostingGoalGapService` — pure, timezone-aware "which goal days in the
    window have nothing scheduled". Unit-tested across a week boundary and a DST change.
  - `App\Support\Workspace\PostingGoal` — the `clients.posting_goal` value object.
  - `App\Support\Workspace\WorkspaceCopy` — PHP agency→business term translation for mails/
    notifications (counterpart of `resources/js/lib/workspace-copy.ts`).
  - `App\Http\Requests\Concerns\ResolvesWorkspaceBrand` — a trait giving shared Form Requests a
    `brand()` that is the route `{client}` when present, else the current workspace's primary brand,
    so one request serves both shells (`StorePostRequest`, `StoreAssetRequest`, `SearchAssetsRequest`,
    `StoreSocialAccountRequest`, `UpdateBrandBrainRequest`, `UpdateBrandReferencesRequest`,
    `ClientPostsTabRequest`, `Campaigns\StoreCampaignRequest`).
- **Phase B-5 publishing without vendor keys:**
  - `App\Contracts\PublishCredentials` + `App\Services\Publishing\OrganizationOrPlatformCredentials`
    (B-5.1) — the single seam for Upload-Post keys/secrets and the publish profile; byo (agency key)
    vs managed (Alima platform key + the org's `upload_post_profile`). Full detail in
    `.junie/modules/publishing.md`.
  - `App\Actions\Workspace\EnsureManagedPublishProfileAction` (B-5.1) — lazily mints + persists the
    managed org's `upload_post_profile` (DB only; the vendor profile is created by the adapter on
    first connect).
  - `App\Actions\SocialAccounts\ConnectManagedAccountAction` (B-5.2) — one-tap connect: find-or-create
    the brand's account for a platform with defaults from the brand/org, then return the hosted
    connect URL via `ConnectSocialAccountAction`. The find-or-create is transactional; the vendor
    call runs after it.
  - `PublishFailureReason` + the `PostPublishFailed` email variant + per-channel retry (B-5.3) — see
    `.junie/modules/publishing.md`.
- **Phase B-6 AI (local/seasonal idea engine, B-6.1):** `App\Contracts\EventCalendar` +
  `App\Services\Events\StaticEventCalendar`, `App\Services\AI\IdeaContextBuilder` +
  `App\Support\AI\IdeaContext`, the `App\Contracts\LocalIdeaProvider` contract (DeepSeek/Null),
  `App\Actions\AI\GenerateBrandIdeasAction` (the `/ideas` "More ideas" generator), and
  `App\Actions\AI\GenerateDailyIdeaAction` + the `business:generate-daily-ideas` command (the Today
  "Idea of the day", cached per brand/local day). Usage is logged via `App\Services\AI\AiTextUsage`.
  Internals in `.junie/modules/ai.md` ("Local & seasonal idea engine"). `GetBusinessDashboardAction`'s
  `ideaOfTheDay` now reads the cached idea; the `/ideas` "More ideas" button routes through
  `GenerateBrandIdeasAction`.
- **Phase B-6 AI (Image Studio presets, B-6.5):** `App\Enums\BusinessImagePreset` +
  `App\Support\AI\Images\BusinessImagePresetComposer` turn a preset + one line of text into a valid
  `GenerateImageSetData`; `BusinessImageStudioPresenter` builds the page prop. Surfaced on a new
  `business/studio` page via `Business\ImageStudioController::{index,generate}` (`business.studio.*`,
  `throttle:ai-images`), which runs the set through the shared `GenerateImageSetAction` (quota +
  rate limit reused, no new engine). Reuses the agency studio's tile/lightbox/polling components and
  the shared `ai-image-*` routes. Internals in `.junie/modules/ai.md` ("Image Studio presets").
- **Phase B-6 AI (repurpose across platforms, B-6.4):** `App\Contracts\CaptionRepurposer` +
  `App\Actions\AI\RepurposeCaptionsForPostAction` (per-platform native variants, guardrailed and
  clamped to each platform's limits). Shared `posts.ai.repurpose` endpoint; the editor's compose
  section fills per-platform overrides in unsaved state. Internals in `.junie/modules/ai.md`
  ("Repurpose across platforms").
- **Phase B-6 AI (plan my week, B-6.3):** `App\Actions\AI\GenerateWeeklyPlanAction` (fills the goal's
  gaps for the next 7 days with `ai_post_ideas` plan items — new `planned_for` column — carrying
  best-time slots and local/seasonal content; idempotent, guardrailed, metered), with
  `AcceptWeeklyPlanItemsAction` / `DiscardWeeklyPlanItemsAction` for bulk accept (→ draft `Idea`
  posts) / discard. Surfaced on `/ideas` via `Business\IdeaController::{generatePlan,acceptPlan,
  discardPlan}` (`business.plan.*`). Internals in `.junie/modules/ai.md` ("Plan my week").
- **Phase B-6 AI (caption from a photo, B-6.2):** `App\Contracts\VisionProvider` +
  `App\Services\AI\GeminiVisionProvider`/`NullVisionProvider`, and
  `App\Actions\AI\SuggestCaptionsFromMediaAction` (brand image asset → on-brand captions, guardrailed,
  usage-logged). Surfaced on `/media` via `Business\MediaController::captions`
  (`business.media.captions`, `throttle:ai`, Owner/Manager/Creator). Internals in
  `.junie/modules/ai.md` ("Caption from a photo").
- **Phase B-7 phone-first creation:**
  - `App\Http\Controllers\Business\QuickPostController` (B-7.2/B-7.3) — `create`/`store`/`share`. Thin:
    resolves the brand from `CurrentWorkspace` and composes `CreateQuickPostAction` +
    `QuickSchedulePostAction` + `NextSlotService`.
  - `App\Actions\Posts\CreateQuickPostAction` (B-7.2) — assembles the draft (one asset, caption,
    pre-timed targets) in one transaction by composing the shared `CreatePostAction` and attaching
    the asset; scheduling stays a separate hop (`QuickSchedulePostAction`), as the editor does.
  - `App\Services\Business\NextSlotService` (B-7.2) — pure, timezone-aware "Next free slot" from the
    posting goal (reuses `PostingGoalGapService::effectiveDays`) + the `ai.plan.default_hour`,
    skipping days that already have a post and today once the hour has passed.
  - `App\Contracts\PushNotifier` + `App\Contracts\PushDeliveryResult` + `App\Services\Push\WebPushNotifier`
    (B-7.4, bound in `AppServiceProvider`) — the single seam for web-push delivery. `isConfigured()`
    = push enabled + a VAPID key pair; the default logs (and returns `Failed`) until a VAPID
    transport is bound, so the feature ships dark. `App\Support\Push\PushMessage` is the payload DTO
    (title/body/url/tag/icon → the SW's `push` handler).
  - `App\Actions\Push\SavePushSubscriptionAction` / `DeletePushSubscriptionAction` (B-7.4) — store
    (upsert on `endpoint_hash`, re-asserting ownership) and opt-out (delete the caller's own row).
  - `App\Actions\Push\SendPostPushRemindersAction` (B-7.4) + the `push:send-post-reminders` command
    (every 5 min, `SchedulerHeartbeat`) — pushes "your post goes out at …" to a business org's
    subscriptions when a scheduled target enters the lead window (`config('push.reminder_lead_minutes')`),
    idempotent via `post_targets.push_reminder_sent_at`, pruning `Gone` subscriptions. Never throws
    for one dead endpoint.
  - The `pwa` shared Inertia prop (`HandleInertiaRequests::sharedPwa`, business-only) carries
    `{pushEnabled, pushPublicKey}`; the frontend `PwaProvider` (manifest+SW+install card, business
    shell only), `use-push.ts` hook and `PushRemindersCard` (Profile settings) consume it. Static
    assets: `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `public/icons/*`.
    Config in `config/push.php`.
- **Phase B-8 insights for one brand:**
  - `App\Actions\Business\GetBusinessInsightsAction` (B-8.1) — now also reads the shared
    `App\Services\Analytics\EngagementMetrics` scoped to the primary brand (reach/engagement by
    platform, top posts, follower growth) on top of the consistency slice, plus `BestTimeService`
    for best times. `hasMetrics` flips once a channel has a reading. No revenue/client/staff sections.
  - `App\Services\Business\BestTimeService` (B-8.3) — per connected account, the account's learned
    slot (shared analytics `BestTimeService`) when there's enough history, else a platform default
    (`config('analytics.best_time.defaults')`), each in the account's timezone. `forBrand()`,
    `suggestForAccount()`, `defaultSlotFor()`.
  - `App\Contracts\InsightsSummarizer` (bound DeepSeek/Null like `LocalIdeaProvider`) +
    `App\Actions\AI\SummarizeInsightsAction` (B-8.2) — up to three PII-scrubbed plain-language
    takeaways from compact facts, cached once per brand per ISO week, metered in `ai_usage_logs`,
    empty when AI is off. `summarizeInsights()` added to both AI providers.
  - `App\Mail\WeeklyInsightsDigestMail` + `App\Actions\Business\SendWeeklyInsightsDigestAction` +
    `business:send-insights-digest` command (B-8.2) — the weekly Monday digest to Owners/Managers of
    active (published, not opted-out) business orgs; hourly sweep acts at the local send hour,
    idempotent per org and ISO week (`SchedulerHeartbeat`).
  - `App\Actions\Business\BuildMediaKitAction` (+ `kitFor()` / `newToken()`),
    `UpdateMediaKitAction` / `ShareMediaKitAction` / `RevokeMediaKitAction`, and
    `GenerateMediaKitPdfAction` (B-8.4) — the media kit assembled only from already-public data
    (published posts, public follower counts), its opt-in revocable public link, and the branded PDF
    (`pdf.media-kit`). `Business\MediaKitController` (owner editor at `/media-kit`) and the public
    `MediaKitShareController` (`/kit/{token}`) share `resources/js/components/media-kit/media-kit-view.tsx`.
- **Phase B-3 post workflow** (the solo-owner / small-team lifecycle) lives in
  `.junie/modules/posts.md`: `App\Contracts\PostWorkflow` + `AgencyPostWorkflow`/`BusinessPostWorkflow`
  + `PostWorkflowResolver` (B-3.1), `QuickSchedulePostAction` (B-3.2), the Owner-only review setting
  wiring (B-3.3), and `CreateSharePreviewAction`/`RecordPreviewFeedbackAction` for the read-only
  partner/sponsor "Share preview" link (B-3.4). `CreatePrimaryBrandAction` now sets the primary
  brand's `default_approval_mode = internal_only` so business posts never carry `client_required`.

## Routes / Controllers
**Phase B-2 routes:**
- Public (guest, `throttle:6,1`): `GET/POST /start` → `Organizations\BusinessRegistrationController`
  (`organizations.start` / `organizations.start.store`), validated by
  `StoreBusinessRegistrationRequest` (brand name, `brand_kind`, timezone, currency, owner name/email/
  password). Renders the `organizations/start` Inertia page (AuthLayout). On success the owner is
  signed in and redirected to `business.onboarding`. The agency signup and `/start` cross-link.
- `super-admin.organizations.store` gains an optional `type` (agency|business); a business choice
  runs `CreatePrimaryBrandAction` through the shared `RegisterOrganizationAction`.
- Business onboarding group (`auth`, `verified`, `org.member`, `workspace:business`, prefix
  `/welcome-setup`, name `business.`) → `Business\OnboardingController`:
  `GET /welcome-setup` (`business.onboarding`, renders `business/onboarding`),
  `PUT …/brand` (`business.onboarding.brand`, `UpdateBusinessBrandRequest` → `UpdateClientAction`),
  `POST …/brand-profile` (`business.onboarding.draft`, `throttle:ai`, `DraftBrandProfileRequest` →
  `DraftBrandProfileAction`, returns the draft as JSON; **not** gated on `ai.enabled` so the empty
  draft fallback still works), `PUT …/voice` (`business.onboarding.voice`, `SaveBrandVoiceRequest` →
  `UpdateBrandBrainAction`),
  `POST …/accounts` (`business.onboarding.accounts.store`, `StoreSocialAccountRequest` →
  `CreateSocialAccountAction`, returns `back()` so the "Connect accounts" step stays in the wizard —
  deliberately separate from `business.accounts.store`, which redirects to the accounts page; the
  brand is implicit via `ResolvesWorkspaceBrand`),
  `POST …/skip` and `POST …/complete` (stamp `onboarding_completed_at`).
- `App\Http\Responses\LoginResponse` sends a business owner who still `needsOnboarding()` to
  `/welcome-setup` after login, otherwise `/dashboard`.

**Phase B-3 routes:**
- `GET/PUT /settings/workspace` (`workspace-settings.edit`/`.update`, group
  `auth`/`verified`/`org.member`/`workspace:business`) → `Settings\WorkspaceController`, the
  Owner-only business settings page (`resources/js/pages/settings/workspace.tsx`, with a "Workspace"
  entry in the settings nav for business owners) holding the "require post review" switch.
- `POST /posts/{post}/quick-schedule` (`posts.quick-schedule`) and
  `POST /posts/{post}/share-preview` (`posts.share-preview`, `throttle:6,1`) on the shared post
  routes; `POST /review/{token}/preview-feedback` (`review.preview-feedback`) on the public review
  group. See `.junie/modules/posts.md`.
- `OrganizationWelcomeMail` + `emails/organization-welcome.blade.php` have a business variant (no
  agency language; CTA points at `business.onboarding`).

The onboarding wizard (`resources/js/pages/business/onboarding.tsx`, standalone layout, mobile-first)
has four steps: **Your brand** and **Your voice** (AI quick-start) are fully wired to the B-2 Actions;
**Connect accounts** and **Your first post** wire to the existing shared flows (the `clients.*`
social-account connect endpoints and `clients.posts.store` → editor, brand id resolved from
`CurrentWorkspace`) pending the richer B-5.2 / B-6.2 versions.

**Phase B-4 routes (the business shell):** a route group (`auth`/`verified`/`org.member`/
`workspace:business`, name prefix `business.`) with brand-implicit URLs, each thin controller in
`App\Http\Controllers\Business\*` resolving the brand from `CurrentWorkspace`:
- `GET /today` → `DashboardController` (B-4.3 dashboard, `business/today`).
- `GET /posts` → `PostController@index` (`business/posts/index`); `POST /posts/new` →
  `create` (empty draft → shared `posts.edit`); `POST /posts` → `store` (`CreatePostAction`).
- `GET /ideas` → `IdeaController@index` (`business/ideas`, persisted trends/ideas, "Use this idea" →
  shared `ai-post-ideas.persist`); `POST /ideas/generate` (`throttle:ai`, reuses `RecommendPostsAction`).
- `GET /media` → `MediaController@index` (`business/media/index`); `GET /media/library` (JSON paging,
  reuses `SearchClientAssetsAction`); `POST /media` (`CreateAssetAction`); deletion reuses the shared
  `assets.destroy` (JSON). A "Create an image" header link points at the Image Studio (`/studio`).
- `GET /studio` → `ImageStudioController@index` (`business/studio`, B-6.5 preset Image Studio);
  `POST /studio/generate` (`throttle:ai-images`) composes a preset into a set and runs the shared
  `GenerateImageSetAction`. The brand-agnostic `ai-image-*` routes (poll/use/refine/finalise/download)
  are reused. `/studio` belongs to the Media sidebar chapter.
- `GET /collections` → `CollectionController@index` (`business/collections`, campaigns renamed);
  `POST /collections` (`CreateCampaignAction`); each collection links to `/posts?campaign=…`.
- `GET/PUT /brand` → `BrandController` (`business/brand`): the Brand Brain (`UpdateBrandBrainAction`),
  `PUT /brand/profile` (basics + posting goal, `UpdateBrandProfileRequest` → `UpdateClientAction`),
  `PUT /brand/references` (`SyncBrandReferenceAssetsAction`).
- `GET /accounts` → `AccountController` (`business/accounts`): **B-5.2** now one card per enabled
  platform, each opening the one-tap hosted connect via `POST /accounts/connect/{platform}`
  (`business.accounts.connect-platform` → `connectPlatform`, managed credentials, no API keys). Also
  `POST /accounts` (`CreateSocialAccountAction`), `PUT/DELETE /accounts/{socialAccount}`, `POST
  /accounts/{socialAccount}/connect` (reconnect an existing account). The shared
  `social-accounts.callback` routes a business org back to `business.accounts.index`.
- `GET /insights` → `InsightController` (`business/insights`, `GetBusinessInsightsAction` +
  deferred `SummarizeInsightsAction` takeaways, B-8.1/B-8.2).
- **B-8.4:** `GET /media-kit` → `MediaKitController@index` (`business/media-kit`, deferred preview);
  `PUT /media-kit` (bio), `POST /media-kit/share` / `DELETE /media-kit/share` (opt-in/revoke),
  `GET /media-kit/pdf`. Public (guest, `throttle:public-token`): `GET /kit/{token}` →
  `MediaKitShareController@show` (`share/media-kit`), `GET /kit/{token}/pdf`.
- **B-7.2/B-7.3:** `GET /posts/quick` → `QuickPostController@create` (`business.posts.quick`,
  standalone mobile page `business/posts/quick`); `POST /posts/quick` → `store`
  (`business.posts.quick.store`); `POST /posts/quick/share` → `share` (`business.posts.quick.share`,
  `throttle:30,1`, the manifest's Web Share Target action).
- **B-7.4:** `POST /push/subscribe` (`business.push.subscribe`) / `DELETE /push/subscribe`
  (`business.push.unsubscribe`) → `PushSubscriptionController`. Per-user, per-browser; subscribe
  404s when push isn't configured.
- **Step CG-1.2 (competitor-gap Phase CG-1):** `GET/POST /grid` → `Business\GridController`
  (`business.grids.*`) is the grid planner hub + create; the planner, tiles, workflow, production and
  AI are the shared brand-agnostic `grids.*` routes. Grid is a Posts sub-item + ⌘K. The agency
  `/grids` hub is now `workspace:agency`-guarded (redirecting a business org to `/grid`). Details in
  `grid-planner.md`.
- **Step CG-1.1 (competitor-gap Phase CG-1):** `GET /inbox` is the **shared** `InboxController`
  (the `workspace:agency` guard was dropped; see `inbox.md`), reached by the business shell like the
  calendar. The shared `inbox/index` page flips `multiClient` off and shows business copy from the
  `workspace` prop; a new `showAssignment` prop hides assignment for a solo seat. Business **Inbox**
  chapter (04) + ⌘K; open reply-needed comments appear in the Today dashboard's `needsYou`
  (`GetBusinessDashboardAction`, `type: 'comment'`, linking to `/inbox`).
- `GET /calendar` is the **shared** `CalendarController`; the page passes `multiClient={false}` for a
  business workspace, hiding the client filter. **Team** links to the shared `staff.*` (Owner-only);
  **Settings** to the shared settings pages (plus `/settings/workspace`, B-3.3).
- Login (`LoginResponse`) sends a business owner with onboarding done to `/today`.
- Navigation/⌘K: `businessChapters` (`lib/chapters.ts`) drive the sidebar (`app-sidebar.tsx`
  `businessGroups()`) and command palette, both switched by the shared `workspace.type` via the
  `useWorkspace()` hook.

**Phase B-1 — guarding the agency-only surface:**
- `App\Http\Middleware\EnsureWorkspaceType` (aliases `workspace:agency` / `workspace:business`,
  registered in `bootstrap/app.php`). Wrong-type orgs are redirected to the equivalent surface when
  one exists (the map fills in Phase B-4) or 404 otherwise — never 403, so the feature isn't
  advertised.
- Inertia shares a `workspace` prop (`HandleInertiaRequests`): `{ type, brand: {id,name}|null,
  roleLabels }`, resolved defensively so a misconfigured org reports `brand: null` rather than
  500-ing every page.

### B-0.2 — inventory of client-scoped surfaces (classification of `route:list`)
Every org-workspace route is **agency-only**, **shared**, or **business-only** (none yet). Guards
enforce the agency-only set below; policies back them up.

**Agency-only — guarded with `workspace:agency` now** (pure CRM / billing / client-portal /
client-reporting, no business equivalent):
- Clients CRM: `clients.*` resource (index, create, store, show, edit, update, destroy),
  `clients.restore`, `clients.purge`; CRM tabs `clients.billing`, `clients.portal-access`,
  `clients.briefs`, `clients.daily-briefs.index`.
- Billing: all `invoices.*` (index, show, edit, update, destroy, pdf.*, send, mark-paid,
  confirm/revert-payment, finalize, remind, void, duplicate, items, generate-pdf, export) and
  `invoice-items.*`.
- Client portal management: `clients.invitations.store`, `invitations.destroy`,
  `clients.connect-invites.store`, `connect-invites.destroy`.
- Client reporting: `clients.analytics`, `clients.reports.index`, `clients.reports.store`,
  `clients.report-schedule.update`, `client-reports.*`.
- The whole `portal.*` group (also already behind `portal` middleware; a business org never has a
  `ClientReviewer`).

**Agency-only — deferred to Phase B-4 (redirect map)**: the `clients/{client}/*` *engine* routes
that will get a brand-implicit business equivalent — `clients.brand-brain.*`, `clients.posts.*`,
`clients.assets.*`, `clients.media`, `clients.campaigns.*`, `clients.social-accounts.store` +
`social-accounts.facebook.*`, `clients.grids.*`, `clients.ai.*` (trends/ideas/images),
`clients.ai.index`, `clients.posts.index`. Protected by their policies in the meantime; the
`EnsureWorkspaceType::EQUIVALENTS` map is filled when the business routes exist.

**Shared** (org-scoped engine reused by the business shell via its own controllers/URLs in B-4;
unguarded): `calendar`, `dashboard`, `needs-attention`, `posts/{post}.*` (edit/update/transition/
reschedule/comments/retry/etc.), `assets.destroy`, `campaigns.*` + `campaigns/{campaign}/*` (become
"collections"), `grids.*` + `grids/{grid}/*`, `ai-trends.show`, `ai-post-ideas.*`, `ai-image-*`,
org-level `analytics.index|export|pdf` and `analytics.reports.*` (business gets `/insights`),
`staff.*` + `staff.invitations.*` (business `/team`), `notifications.*`, `settings/*` (profile,
security, appearance, organization, ai-usage, tax-rates), `social-accounts.{update,destroy,connect}`.

**Public / token / cross-org (out of scope for the guard)**: `home`, `about`,
`organizations.register(.store)`, `/start` (B-2), `review.*`, `client-invite.*`, `staff-invite.*`,
`connect.*`, `campaign-share.show`, `social-accounts.callback`, `auth.meta.callback`,
`webhooks.upload-post`, and the `super-admin.*` and `preview.*` groups (platform / frozen fixture).

**Policy layer (defence in depth):** `ClientPolicy::create` denies business orgs; `ClientPolicy::
delete` denies deleting any primary brand; `InvoicePolicy` and `ClientInvitationPolicy` deny every
ability for business orgs (policy `before()`); `StorePostRequest`/`UpdatePostRequest` reject
`ApprovalMode::ClientRequired` for business orgs; staff invitations reject non-assignable roles
(`StoreStaffInvitationRequest` + `CreateStaffInvitationAction`).

## Business Rules
- Exactly one primary brand per business org, enforced by the DB (unique index on the generated
  column) **and** `CreatePrimaryBrandAction`.
- A primary brand can never be deleted (it's the whole workspace; removed only by deleting the
  workspace, Step B-13.2).
- `ClientReviewer` and `SuperAdmin` are never assignable in a business org.
- A business org never reaches an agency-only route (middleware) or ability (policy); agency
  behaviour is byte-for-byte unchanged (existing suite stays green).

## Tests
- `tests/Unit/Enums/OrganizationTypeTest.php` — labels.
- `tests/Unit/Enums/RoleWorkspaceLabelTest.php` — `labelFor()` / `assignableFor()` per type.
- `tests/Unit/Models/OrganizationTypeHelpersTest.php` — `isBusiness`/`isAgency`/`ofType`.
- `tests/Feature/Workspace/PrimaryBrandTest.php` — unique-index + Action reject a second primary;
  Action creates the Brand Brain alongside.
- `tests/Feature/Workspace/CreatePrimaryBrandActionTest.php` — transaction + fields.
- `tests/Unit/Workspace/CurrentWorkspaceTest.php` — agency (null brand), business (brand resolved),
  misconfigured business (exception + log).
- `tests/Feature/Workspace/WorkspaceGuardsTest.php` — a business Owner hits every agency-only route
  (data provider) and gets 404; agency Owner still 200/allowed.
- `tests/Feature/Workspace/WorkspacePolicyTest.php` — the policy denials.
- `tests/Feature/Workspace/BusinessStaffRoleTest.php` — `client_reviewer`/`super_admin` rejected for
  business; labels in the shared `workspace` prop.
- `tests/Feature/Workspace/WorkspaceSharedPropsTest.php` — the `workspace` Inertia prop per type.
- `tests/Feature/BusinessRegistrationTest.php` — `/start` end-to-end (business org + primary brand +
  Brand Brain + owner + welcome mail + verification + redirect), brand-kind/validation, duplicate
  email, throttle.
- `tests/Feature/Business/OnboardingTest.php` — `OnboardingProgress` items flipping from data; the
  wizard routes (guard, brand save, voice save, AI draft returns-without-saving, skip/complete, the
  post-login redirect to `/welcome-setup`).
- `tests/Unit/Services/Web/HttpWebsiteSummarizerTest.php` — readability + SSRF guard (loopback,
  private, link-local, IPv6 loopback, non-http scheme all blocked with nothing sent).
- `tests/Feature/AI/DraftBrandProfileActionTest.php` — happy path (fake provider, nothing persisted,
  `ai_usage_logs` recorded), provider failure → empty draft, website summarised into the input, an
  SSRF-blocked URL yields no website text.
- `tests/Feature/SuperAdmin/OrganizationManagementTest.php` — super-admin can onboard a business
  workspace (primary brand created) and defaults to agency with no type.
- `tests/Unit/Contracts/AiProviderTest.php` + `tests/Feature/AI/AiActionsTest.php` — updated for the
  new `draftBrandProfile` contract method.
- `tests/Unit/Services/Posts/PostWorkflowTest.php` — **B-3.1:** both workflows' transition tables
  (data providers, review on/off), the agency-unchanged regression, `defaultApprovalMode`,
  `requiresReview`, and the resolver picking by org type.
- `tests/Feature/Business/QuickSchedulePostTest.php` — **B-3.2:** one-tap schedule, "Post now"
  retime, review-on → internal review (notifying Owners/Managers, not the actor or a Creator),
  checklist failure leaves the post untouched, a Viewer is forbidden.
- `tests/Feature/Business/WorkspaceReviewSettingTest.php` — **B-3.3:** the Owner toggle, the
  workflow output changing with it, an in-flight `internal_review` post still approvable after
  switching off, non-Owner denial, and an agency 404 on `/settings/workspace`.
- `tests/Feature/Business/SharePreviewTest.php` — **B-3.4:** view-only / comment-only /
  approval-enabled links, that feedback records a comment/approval without moving the post, the
  agency `review.decide` 404ing a business token, expiry, and the Owner/Manager-only mint policy.
- `tests/Unit/Services/Publishing/OrganizationOrPlatformCredentialsTest.php` — **B-5.1:** byo vs
  managed key/secret/profile resolution, a stable per-org profile, one org never seeing another's
  profile, the `publish_mode` override, and an empty byo key.
- `tests/Unit/Services/Publishing/UploadPostAdapterManagedModeTest.php` — **B-5.1:** a managed org
  publishes with the platform key + its own profile, and the key never reaches the logs.
- `tests/Feature/Business/ManagedAccountConnectTest.php` — **B-5.2:** one-tap connect creates the
  brand's account and returns the hosted URL (platform key used), the callback connects it under the
  primary brand, a tampered callback is refused, reconnecting an expired account reuses the row, an
  unknown platform 404s, and an agency can't reach the route.
- `tests/Unit/Enums/PublishFailureReasonTest.php` — **B-5.3:** each known vendor error classifies to
  the right cause, reconnect causes aren't offered a bare retry, and `describe()`'s payload.
- `tests/Feature/Business/PublishFailureNotificationTest.php` — **B-5.3:** a business failure emails
  as well as pings in-app; an agency failure stays database-only.
- `tests/Feature/Publishing/PublishPipelineTest.php` (extended) — **B-5.3:** retrying a single failed
  channel leaves the others untouched, and a foreign target id is rejected.
- `tests/Feature/Business/PwaAssetsTest.php` — **B-7.1/B-7.3:** the manifest is valid + installable
  (icons, scope), the share target points at `business.posts.quick.share`, the service worker +
  offline shell exist, and the shared Blade root carries no manifest link (agency unaffected).
- `tests/Feature/Business/QuickPostTest.php` — **B-7.2:** the page loads scoped to the brand;
  create+schedule, "post now" (immediate), review-on → internal review, a cross-brand asset
  rejected, caption/account validation, a Viewer forbidden, an agency 404.
- `tests/Unit/Services/Business/NextSlotServiceTest.php` — **B-7.2:** today-before-hour, roll to
  tomorrow, posting-goal days, skipping taken days, brand-timezone handling.
- `tests/Feature/Business/ShareToAlimaTest.php` — **B-7.3:** shared media becomes an asset and
  redirects into quick post with it attached; auth required; media validated; agency 404.
- `tests/Feature/Business/PushRemindersTest.php` (+ `tests/Support/FakePushNotifier.php`) — **B-7.4:**
  subscribe stores, re-subscribe upserts, opt-out deletes, subscribe 404s when unconfigured, the
  reminder sweep pushes once (idempotent), the lead window is respected, a `Gone` subscription is
  pruned, and nothing is sent when push is unconfigured — all against the faked notifier.
- `tests/Unit/Services/Business/BestTimeServiceTest.php` — **B-8.3:** learned slots used where ready,
  platform defaults elsewhere, the general-default fallback, no accounts, and invalid-timezone.
- `tests/Feature/Business/BusinessInsightsTest.php` — **B-8.1:** consistency without metrics,
  reach/engagement/followers/best-times once metrics land (and the learned-only `best_times` stripped),
  the page loads for a business Owner, and an agency 404.
- `tests/Feature/Business/InsightsTakeawaysTest.php` — **B-8.2:** summary metered + weekly-cached
  (provider hit once), and unavailable (no cache) when the provider returns nothing.
- `tests/Feature/Business/WeeklyInsightsDigestTest.php` — **B-8.2:** emails Owners/Managers with the
  takeaways when there's activity; no send on opt-out, no published posts, or an agency org.
- `tests/Feature/Business/MediaKitTest.php` — **B-8.4:** built from public data only (published posts,
  no drafts/emails), opt-in (404 until shared), share→view→revoke→404, a new link invalidates the old,
  owner page loads (agency 404), bio save, and the PDF download.

## Known Gaps / Open Questions
- **Phase B-8 (insights for one brand) is done**: `/insights` now shows reach/engagement, top posts,
  follower growth and best times (B-8.1/B-8.3), plain-language AI takeaways + the weekly email digest
  (B-8.2), and there's a public, revocable creator media kit at `/media-kit` → `/kit/{token}`
  (B-8.4). Plans/limits and payments (B-9) and teams (B-10) are later phases. Two deliberate
  follow-ups: (1) the media kit's "past partners" section stays empty until the brand-deals tracker
  (B-11.2) lands; (2) the business `BestTimeService` is available to the quick-post / weekly-plan slot
  pickers but those still use the posting-goal default hour (`NextSlotService`) — wiring the learned
  slot in is an additive follow-up.
- **B-7 phone-first creation is done.** One deliberate follow-up remains: (1) the quick post upload
  shows an indeterminate state, not byte-level progress — true direct-to-storage progress awaits
  I-7.1. **Step CG-1.3 closed the push follow-up:** real VAPID delivery (`MinishlinkWebPushNotifier`,
  `minishlink/web-push`) is bound by `config('push.transport') = webpush`, and the two remaining
  notifications (publish failure, "nothing scheduled tomorrow") are built. To deliver in production:
  generate a VAPID key pair, set `PUSH_ENABLED`/`PUSH_VAPID_*`/`PUSH_TRANSPORT=webpush`, then record
  the Android-Chrome / iOS-Safari (installed PWA) device check here. Dev/tests stay on the logger.
- B-6.1's `EventCalendar` is a hand-curated static dataset for GB/US + global (open question §5.5);
  other countries fall back to the global set (and, when unresolved, the `ai.ideas.default_country`
  home market). A paid holiday API can replace `StaticEventCalendar` behind the same contract.
- B-6.1 records idea generation in `ai_usage_logs` (`AiTextUsage`) but, like the rest of the AI
  surface, there is no allowance *enforcement* until B-9.2.
- **B-5 managed publishing** requires `UPLOAD_POST_PLATFORM_KEY` (and, for signed webhooks,
  `UPLOAD_POST_PLATFORM_WEBHOOK_SECRET`) in the environment; until set, a managed org's publish calls
  go out with an empty key and the vendor rejects them (surfaced as a plain-language failure).
  One Upload-Post profile per org holds every network, so all of a brand's connected accounts share
  the org's `upload_post_profile`.
- `DraftBrandProfileAction` records usage in `ai_usage_logs` but there is no allowance *enforcement*
  yet — that lands with the `Entitlements` metering in B-9.2.
- The agency campaign *workspace* (`campaigns.show` and its tabs) remains a shared, unguarded
  org-scoped surface; the business shell simply never links to it (Collections links to the filtered
  posts list instead). Fully business-ifying or guarding that surface is out of B-4. **Step CG-1.2
  closed the grids half of this gap:** the agency `/grids` hub is now `workspace:agency`-guarded and
  the business shell has its own `/grid` hub (`business.grids.*`) reusing the shared planner.
- **B-6.5 is done**: the business shell now has its own Image Studio at `/studio` (preset-driven),
  so `clients.ai.images.store` stays `workspace:agency` by design; business image generation goes
  through `business.studio.generate`, and the brand-agnostic `ai-image-*` routes remain shared.
  The presets only pre-fill art direction — image art-direction tuning (palette/reference editing)
  is intentionally not exposed in the business studio (presets keep it one-tap).

## Changelog
- 2026-10-27: Step CG-1.4 (competitor-gap Phase CG-1) — tied the switched-on surfaces into Today.
  `GetBusinessDashboardAction::needsYou()` now surfaces grid-order-at-risk rows (`type: grid_risk`,
  re-verified via `GridOrderRiskService`, linking to the planner) alongside the CG-1.1 comment rows;
  `OnboardingProgress` gained the optional "Plan your grid" item for Instagram creators. Tests:
  `BusinessDashboardTest`, `OnboardingTest`. No new columns/config.
- 2026-10-27: Step CG-1.3 (competitor-gap Phase CG-1) — delivered push reminders. Added the real
  `MinishlinkWebPushNotifier` transport behind a `push.transport` config switch (default `log`), the
  publish-failure push (business orgs, from `ResolvePostPublishOutcomeAction`) and the daily
  "nothing scheduled tomorrow" nudge (`push:send-empty-tomorrow`). Internals in `notifications.md`.
  New config: `push.transport`. New dependency: `minishlink/web-push ^11.0`. VAPID keys + the device
  check are owner handoff items (see Known Gaps).
- 2026-10-27: Step CG-1.2 (competitor-gap Phase CG-1) — the Instagram grid planner switched on for the
  business shell. New `Business\GridController` + `business/grids/index.tsx` (`/grid` hub + create),
  reusing the shared planner/production routes; `StoreGridPlanRequest` made brand-implicit via
  `ResolvesWorkspaceBrand`; the agency `/grids` hub guarded with `workspace:agency` + an
  `EnsureWorkspaceType` redirect to `business.grids.index`. Grid as a Posts sub-item + ⌘K. Details in
  `grid-planner.md`. Tests: `BusinessGridTest`. No new columns/config.
- 2026-10-27: Step CG-1.1 (competitor-gap Phase CG-1 — "switch on what's built") — the social inbox
  switched on for the business shell. Made `/inbox` a shared surface (one `InboxController`/page, the
  page adapts via the `workspace` prop like the calendar), added a `showAssignment` prop (hidden for a
  solo seat), org-scoped the assignee list, added the business Inbox chapter (04) + ⌘K, and surfaced
  open reply-needed comments on Today's `needsYou`. Details in `inbox.md`. Tests: `BusinessInboxTest`,
  `BusinessDashboardTest`. No new columns/config.
- 2026-10-25: Fixed a 404 on the onboarding "Connect accounts" step. The wizard's "Add" button posted
  to the agency-only `clients.social-accounts.store` route (gated by `workspace:agency`), which 404s
  for a business/creator org. Added `OnboardingController::storeAccount` + the brand-implicit
  `POST /welcome-setup/accounts` (`business.onboarding.accounts.store`) route, reusing
  `StoreSocialAccountRequest` + `CreateSocialAccountAction` and returning `back()` so the user stays in
  the wizard; `business/onboarding.tsx` now calls `OnboardingController.storeAccount`. Tests:
  `OnboardingTest` (business owner adds an account inline; agency 404s). Verified: Pint, PHPStan L7,
  `OnboardingTest` 14/14.
- 2026-10-24: UX overhaul (business propagation, `.junie/plans/ux-overhaul.md`) — the patterns from
  the agency flagship brought to the business shell. One Create already covered business (the header
  `New` button + `useCreateActions`); "one front door" is moot (Today is the single home). Added a
  **brand setup checklist** on Today: `GetBusinessDashboardAction` returns a `setup` payload
  (`accounts`/`brand`/`goal`/`post`/`complete`) and `business/brand-setup-checklist.tsx` (on the
  shared `components/setup-checklist.tsx`) guides connect → brand → goal → first post, hiding once
  done. `BrandBrain::isFilled()` extracted and reused by both setup checklists. Tests:
  `BusinessDashboardTest` (incomplete for a new brand; completes once set up). Verified: `tsc`,
  `vp check`, guards, 130 vitest, `BusinessDashboardTest` 9/9, `ClientTabsTest` 13/13.
- 2026-10-23: Phase B-8 (insights for one brand) implemented — single-brand Insights (consistency +
  reach/engagement/top-posts/followers via the shared `EngagementMetrics`, plus best times) in
  `GetBusinessInsightsAction` and the rebuilt `business/insights` page (B-8.1); `App\Services\Business\
  BestTimeService` (learned-or-default, per account, in the account timezone) (B-8.3); the
  `InsightsSummarizer` contract + `SummarizeInsightsAction` (weekly-cached, metered takeaways) with a
  deferred `/insights` panel and the weekly `WeeklyInsightsDigestMail` + `SendWeeklyInsightsDigestAction`
  + `business:send-insights-digest` command, opt-out via `organizations.insights_digest_opt_out`
  (B-8.2); and the creator media kit — `media_kits` table + `MediaKit`, `BuildMediaKitAction` +
  update/share/revoke + `GenerateMediaKitPdfAction`, `Business\MediaKitController` (`/media-kit`),
  public `MediaKitShareController` (`/kit/{token}`), the `share/media-kit` page and shared
  `media-kit-view` component (B-8.4). New columns: `organizations.insights_digest_opt_out`; new table:
  `media_kits`. New config: `analytics.best_time.defaults`, `analytics.insights_digest.send_hour`.
- 2026-10-23: Phase B-7 (phone-first creation) implemented — the installable PWA (manifest +
  `public/sw.js` offline shell + `public/icons/*` + the business-only `PwaProvider`; agency pages
  untouched) (B-7.1); the quick post flow (`QuickPostController`, `business/posts/quick` page,
  `CreateQuickPostAction`, `NextSlotService`) (B-7.2); Share-to-Alima (manifest `share_target` →
  `QuickPostController::share`) (B-7.3); and push reminders (the `push_subscriptions` table +
  `PushSubscription`, the `PushNotifier` contract + `PushDeliveryResult` + `WebPushNotifier`, the
  `business.push.*` opt-in, `SendPostPushRemindersAction` + the `push:send-post-reminders` command,
  `post_targets.push_reminder_sent_at`, and the SW push handlers) (B-7.4). New columns:
  `post_targets.push_reminder_sent_at`. New config: `config/push.php`. New shared Inertia prop `pwa`
  (business-only). The failure / "nothing scheduled tomorrow" pushes reuse the same contract and are
  a documented follow-up (publish failures already email + bell via B-5.3).
- 2026-10-23: Step B-6.5 (Image Studio presets) implemented — `BusinessImagePreset` +
  `BusinessImagePresetComposer` + `BusinessImageStudioPresenter`, `Business\ImageStudioController`,
  the `business.studio.*` routes and the `business/studio` page (reusing the Phase 16 engine, the
  shared `ai-image-*` routes and the agency studio's tile/lightbox/polling components), plus a
  "Create an image" entry on `/media` and `/studio` folded into the Media sidebar chapter.
- 2026-10-23: Step B-6.1 (local/seasonal idea engine) implemented — `EventCalendar` +
  `StaticEventCalendar`, `IdeaContextBuilder` + `IdeaContext`, the `LocalIdeaProvider` contract,
  `GenerateBrandIdeasAction`, `GenerateDailyIdeaAction` + the `business:generate-daily-ideas` command,
  `AiTextUsage`, the Today "Idea of the day" panel, and the `/ideas` "More ideas" button routed
  through the local engine. `GetBusinessDashboardAction.ideaOfTheDay` now reads the cached idea. New
  config: `ai.ideas.*`. Internals in `.junie/modules/ai.md`.
- 2026-10-23: Step B-6.4 ("Repurpose across platforms") implemented — the `CaptionRepurposer`
  contract + DeepSeek/Null, `RepurposeCaptionsForPostAction` (guardrailed, per-platform limit clamp),
  the `posts.ai.repurpose` endpoint, and the editor "Repurpose per platform" button. Internals in
  `.junie/modules/ai.md`.
- 2026-10-23: Step B-6.3 ("Plan my week") implemented — `GenerateWeeklyPlanAction` +
  `AcceptWeeklyPlanItemsAction`/`DiscardWeeklyPlanItemsAction`, the `/ideas` "This week's plan" panel
  + `business.plan.*` endpoints, and the `ai_post_ideas.planned_for` column. New config:
  `ai.plan.default_hour`. Internals in `.junie/modules/ai.md`.
- 2026-10-23: Step B-6.2 (caption from a photo) implemented — the `VisionProvider` contract +
  `GeminiVisionProvider`/`NullVisionProvider`, `SuggestCaptionsFromMediaAction`, the `/media`
  "Caption from photo" dialog + `business.media.captions` endpoint (Owner/Manager/Creator,
  `throttle:ai`), and `AiTextUsage` generalised for Gemini/DeepSeek. New config: `ai.vision.*`.
  Internals in `.junie/modules/ai.md`.
- 2026-10-22: Phase B-5 (publishing without vendor keys) implemented — the `PublishCredentials`
  contract + `OrganizationOrPlatformCredentials` + `PublishMode` enum + `EnsureManagedPublishProfile
  Action`, routing the adapter/webhook/music/engagement credential reads through the seam (B-5.1);
  the one-tap platform-card `/accounts` page + `accounts.connect-platform` route +
  `ConnectManagedAccountAction` (B-5.2); and `PublishFailureReason` + per-channel retry +
  `PostPublishFailed` business email (B-5.3). New columns: `organizations.publish_mode`,
  `organizations.upload_post_profile`. New config: `services.upload_post.platform_key` /
  `platform_webhook_secret`. Internals in `.junie/modules/publishing.md`.
- 2026-10-22: Phase B-4 (the business app shell) implemented — the `business.*` brand-implicit route
  group + thin `Business\*` controllers reusing the engine Actions (B-4.1); `businessChapters` + the
  business sidebar/command-palette + the `/today` login redirect (B-4.2); the `GetBusinessDashboard
  Action` "Today" page (B-4.3); the `workspace-copy.ts` / `WorkspaceCopy` term pass (B-4.4); and the
  `clients.posting_goal` column + `PostingGoal` value object + `PostingGoalGapService` + `/brand`
  posting-goal editor (B-4.5). The deferred `clients/{client}/*` engine routes are now
  `workspace:agency`-guarded and `EnsureWorkspaceType::equivalents()` redirects a business org from
  each agency landing page to its shell equivalent. Shared Form Requests gained
  `Concerns\ResolvesWorkspaceBrand`. New column: `clients.posting_goal`.
- 2026-10-22: Phase B-3 (workflow for one person or a small team) implemented — the `PostWorkflow`
  contract + agency/business implementations + resolver (B-3.1), `QuickSchedulePostAction` + the
  business editor action bar (B-3.2), the `require_post_review` switch + `/settings/workspace`
  (B-3.3), and the "Share preview" partner/sponsor link reusing the review token (B-3.4). New
  columns: `organizations.require_post_review`, `review_tokens.allow_comments`/`allow_approval`.
  Internals documented in `.junie/modules/posts.md`.
- 2026-10-22: Phase B-2 (signup & onboarding) implemented — `/start` business signup +
  type-aware `RegisterOrganizationAction` + welcome-mail variant + super-admin type selector (B-2.1);
  `onboarding_completed_at` + `OnboardingProgress` + `/welcome-setup` redirect/skip (B-2.2); the
  onboarding wizard (B-2.3); and the brand-profile AI quick-start with the SSRF-guarded
  `WebsiteSummarizer` and the `AiProvider::draftBrandProfile` contract method (B-2.4). New columns:
  `clients.brand_kind`, `clients.location`, `organizations.onboarding_completed_at`.
- 2026-10-22: Phase B-0 (decisions, route inventory) and Phase B-1 (OrganizationType, primary brand,
  CurrentWorkspace, agency guards + policies, role mapping) implemented.
- 2026-10-22: `clients.primary_org_id` made VIRTUAL (was STORED) — MySQL rejects a stored generated
  column whose base column (`org_id`) carries an ON DELETE CASCADE FK (error 1215). Verified the
  virtual column + unique index enforce one-primary-per-org on MySQL 8.
