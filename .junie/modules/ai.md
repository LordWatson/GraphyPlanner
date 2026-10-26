# AI (DeepSeek) Module

## Purpose
AI-assisted trend discovery and suggestions for posts, captions, hashtags, alt text, and posting times. Phase 2 added a swap-friendly provider contract first (Step 2.1), a DeepSeek-backed implementation of it (Step 2.2), the Actions that wrap those provider calls for controllers/jobs to use (Step 2.3), the controller/routes/UI that expose them to real users (Step 2.4): the editor's "Suggest with AI" panel and the client dashboard's "Trends & Ideas" panel, a nightly "Daily Brief" digest per client (Step 2.5), and a post-generation safety net — banned-phrase filtering, platform policy warnings, and PII/secret scrubbing (Step 2.6). The remaining provider-selection/cost/ops steps land after.

## Models / Tables
- `App\Models\DailyBrief` (Step 2.5, table `daily_briefs`): `org_id`, `client_id`, `brief_date`, `trends`/`ideas` (json arrays of the serialized `AiTrend`/`AiPostIdea` DTOs, capped at 5 each), `notified_at`. Unique on `(client_id, brief_date)` so a re-run of the nightly job never duplicates a day's brief. `ai_usage_logs`/`ai_feedback` tables are still planned for later steps (2.8, 2.9) and are not implemented.
- `App\Models\AiTrend` (table `ai_trends`): persisted copy of a discovered trend — `org_id`, `client_id`, `title`, `summary`, `platforms` (json), `confidence`. Unique on `(client_id, title)` so re-discovering the same trend on Refresh upserts instead of duplicating. Gives the "Trends & Ideas" panel a row that survives a page refresh and has a real id for its own detail page.
- `App\Models\AiPostIdea` (table `ai_post_ideas`): persisted copy of a recommended idea — `org_id`, `client_id`, `title`, `description`, `platforms` (json), `suggested_caption`, `suggested_post_at`, and a nullable `post_id` (filled in once `RecommendPostsAction::persist()` turns the idea into a real draft `Post`). Unique on `(client_id, title)`, same upsert-on-refresh behavior as `AiTrend`.

## Actions / Services / Contracts
- Contracts:
  - `App\Contracts\AiProvider`: abstraction for AI capabilities with methods `suggestCaptions(Post): AiCaptionSuggestions`, `recommendPosts(Client): AiPostIdeas`, `discoverTrends(Client, platforms[]): AiTrends`. Bound in `AppServiceProvider` the same way `PublishAdapter`/`MusicProvider` are, so the implementation stays swappable/mockable.
- Services:
  - `App\Services\AI\NullAiProvider`: always returns empty result DTOs and never calls an external service. Still the safe fallback binding — `App\Providers\AppServiceProvider` (Step 2.7) resolves `AiProvider::class` to `DeepSeekProvider` only when `config('ai.enabled')` is true **and** `config('ai.deepseek.api_key')` is non-empty; otherwise (either flag off) it resolves to `NullAiProvider`, so a misconfigured/missing key never crashes the app, it just silently no-ops.
  - `App\Services\AI\DeepSeekProvider` (Step 2.2): calls DeepSeek's `/chat/completions` endpoint (`Http::baseUrl(config('ai.deepseek.base_url'))->withToken($apiKey)`) with `response_format: json_object` to get strict JSON back. Builds a shared system prompt per call from `ClientContextBuilder::build()` (client profile + full `BrandBrain::toGrokContext()` markdown + planning/active campaigns) preceded by explicit grounding rules (write in the brand voice, respect do-nots, address audience pain points/offer, tie to a content pillar/campaign, include every `always_use` hashtag, write in the primary content language), plus every `Platform::captionLimit()`. Each user prompt adds method-specific grounding: captions get the post's own campaign (name/status/dates/goal); ideas must name the pillar and pain point/offer they serve; trends must fit the client's industry/markets/audience/pillars and explain how this brand would use them. Validates the decoded JSON against each method's expected shape (`suggestions[]`/`ideas[]`/`trends[]` with required string fields) and retries once if invalid (`requestStructured()`'s `MAX_ATTEMPTS = 2`), logging `info` on start/success, `warning` on a schema retry, `error` on final failure/HTTP rejection — falling back to an empty DTO rather than throwing. A trivial exponential backoff with jitter (`withToken(...)->retry(3, ...)`) is applied on HTTP 429/5xx. Never logs the API key or raw prompt text, only ids/method/model/attempt. The draft caption text it embeds in the captions user prompt is passed through `AiGuardrailService::scrub()` first so obvious PII/secrets in a user's own draft never reach the vendor.
  - `App\Services\AI\AiGuardrailService` (Step 2.6): the post-generation safety net applied by every Step 2.3 Action before a suggestion/idea/trend is ever returned to a controller. `filterCaptionSuggestions()`/`filterPostIdeas()`/`filterTrends()` each drop any item whose text mentions a banned phrase — the client's `BrandBrain::voice['do_nots']` + `hashtag_policy['never_use']`, plus the global `config('ai.guardrails.prohibited_topics')` list — logging a `warning` with the matched phrase (never the full text) and returning a filtered copy with every remaining free-text field passed through `scrub()`. `scrub()` redacts obvious PII/secrets (emails, phone numbers, bearer/API-key-shaped tokens, card-looking numbers) via regex before text is logged or shown to a user. `platformWarnings(Platform, AiCaptionSuggestion)` is a separate, read-only check — in-caption links on link-averse platforms, hashtag-count overages, caption-length overages — that only ever returns human-readable warning strings; it never rewrites the suggestion itself.
  - `App\Support\AI\ClientContextBuilder` (Step 2.10, partial): assembles the per-request "company context" every AI method is grounded in — `build(Client)` renders a markdown briefing of the client profile (name, legal name if different, industry, website, countries, `default_language`, tags), the full Brand Brain (or an explicit "no brand brain yet" note), and its `Planning`/`Active` campaigns (name, status, dates, goal; completed/archived excluded), passed through `AiGuardrailService::scrub()` so PII/secrets typed into a brand brain never reach the vendor. `fingerprint(Client)` is an md5 of that output, used by `DiscoverTrendsAction`'s cache key. `describeCampaign(Campaign)` is reused for the captions prompt. `notes_internal`/billing fields are deliberately excluded. Constructor-defaults its `AiGuardrailService`, the same way `DeepSeekProvider` does.
- Support DTOs (readonly, JSON-serializable):
  - `App\Support\AI\AiCaptionSuggestion`, `AiCaptionSuggestions`
  - `App\Support\AI\AiPostIdea`, `AiPostIdeas`
  - `App\Support\AI\AiTrend`, `AiTrends`

## Actions / Controllers / Routes
- `App\Actions\AI\DiscoverTrendsAction` (Step 2.3): constructor-injects `AiProvider`; `__invoke(Client, array<string> $platforms)` calls `AiProvider::discoverTrends()` and caches the resulting `AiTrends` via the `Cache` facade for 2 hours, keyed on `ai:trends:{client_id}:{sorted,comma-joined platforms}:{ClientContextBuilder::fingerprint()}` so requests for the same platform set (in any order) reuse the cached result, different clients never share an entry, and editing the client's brand brain/profile/campaigns immediately yields fresh, re-grounded trends instead of stale ones for the rest of the TTL. On every cache miss it also upserts each filtered trend into `App\Models\AiTrend` (keyed on `client_id`+`title`) inside `DB::transaction()`, so the "Trends & Ideas" panel has real, refresh-proof rows to list even though the in-memory DTO is still what callers get back. Reads the cache with `Cache::get()`/`Cache::put()` (not `Cache::remember()`) and explicitly checks `instanceof AiTrends`: a stale or incompatible cached value (e.g. unserialized as `__PHP_Incomplete_Class` after the DTO's shape changed) is logged as a `warning` and discarded via `Cache::forget()`, then regenerated — instead of letting a `TypeError` bubble up out of `__invoke()`'s return type.
- `App\Actions\AI\RecommendPostsAction` (Step 2.3): `__invoke(Client)` calls `AiProvider::recommendPosts()`, upserts each filtered idea into `App\Models\AiPostIdea` (keyed on `client_id`+`title`) inside `DB::transaction()` the same way `DiscoverTrendsAction` does for trends, then returns the `AiPostIdeas` DTO. A separate `persist(Client, AiPostIdea)` method — only ever called explicitly by `AiController::persistIdea()`, never automatically — creates a draft `Post` (`status = PostStatus::Idea`, `master_caption` from the idea's `suggestedCaption`) inside `DB::transaction()` and links the matching `AiPostIdea` row's `post_id` to it.
- `App\Actions\AI\SuggestCaptionsForPostAction` (Step 2.3): `__invoke(Post)` calls `AiProvider::suggestCaptions()` and returns the raw `AiCaptionSuggestions`. A separate `apply(Post, AiCaptionSuggestion)` method writes the chosen suggestion's `caption`/`hashtags` into the post's `master_caption`/`hashtags` inside `DB::transaction()`, only when explicitly invoked — the AI never overwrites a post's caption on its own. (DeepSeek's `altText`/`firstComment` fields have no column on `Post` yet, so they're only surfaced via the suggestions DTO for the editor UI to render, not persisted.)
- `App\Actions\AI\GenerateDailyBriefsAction` (Step 2.5): constructor-injects `DiscoverTrendsAction`/`RecommendPostsAction`. `__invoke(?Carbon $date = null)` loops every `ClientStatus::Active` client, skips any that already have a `DailyBrief` for that calendar day (idempotent re-run), calls both Actions, stores the top 5 trends/ideas as a `DailyBrief` inside `DB::transaction()`, then emails every `Role::Owner`/`Role::Strategist` user of the client's organization via `DailyBriefReadyMail` — unless the current hour falls inside the `config('ai.daily_briefs.quiet_hours_start/end')` window (app-local time), in which case the brief is still stored but the email is held back (no backfill) and `notified_at` stays null.
- `App\Http\Controllers\AiController` (Step 2.4): thin controller with three GET endpoints — `trends(Client)`, `ideas(Client)`, `captions(Post)` — each only calling the matching Step 2.3 Action and returning it wrapped as `{trends: [...]}`/`{ideas: [...]}`/`{suggestions: [...]}`. Every endpoint aborts `404` when `config('ai.enabled')` is false and `403` unless the authenticated user both passes the existing `view` policy for the `Client`/`Post` and is `Role::Owner`/`Role::Strategist` within that resource's own `org_id`. `captions()` additionally computes `policyWarnings` (Step 2.6) — an array of per-suggestion warning-string arrays from `AiGuardrailService::platformWarnings()` run against the post's `targetPlatforms()` — and returns it alongside `suggestions` for the editor UI to render. It also has three list → detail endpoints for the now-persisted trends/ideas: `showTrend(AiTrend)` and `showIdea(AiPostIdea)` each render a standalone Inertia detail page (`clients/ai-trend`/`clients/ai-post-idea`), and `persistIdea(AiPostIdea)` calls `RecommendPostsAction::persist()` to turn that one idea into a draft `Post` only when explicitly POSTed from its detail page — never automatically. All three use the same `view`-policy-on-the-owning-`Client` + Owner/Strategist-in-org gating as the rest of the controller.
- `App\Http\Controllers\DailyBriefController` (Step 2.5): a single `index(Client)` GET endpoint returning `{briefs: [...]}` (id, `brief_date`, `trends`, `ideas`, `notified_at`) for that client, newest first, with optional `from`/`to` date-range query params and `pillar`/`campaign` substring filters applied the same way the Step 2.4 "Trends & Ideas" panel filters its ideas. Same 404/403 gating as `AiController`.
- Routes: `GET clients/{client}/ai/trends` (`clients.ai.trends`), `GET clients/{client}/ai/ideas` (`clients.ai.ideas`), `GET posts/{post}/ai/captions` (`posts.ai.captions`) (Step 2.4), `GET clients/{client}/daily-briefs` (`clients.daily-briefs.index`) (Step 2.5), `GET ai-trends/{aiTrend}` (`ai-trends.show`), `GET ai-post-ideas/{aiPostIdea}` (`ai-post-ideas.show`), `POST ai-post-ideas/{aiPostIdea}/persist` (`ai-post-ideas.persist`) — all inside the authenticated `auth`/`verified` group only (intentionally excluded from the `/preview` fixture group, which is read-only/frozen and has no real AI usage).

## Logging / Diagnostics
How to trace an empty Trends/Ideas/Captions result in `storage/logs/laravel.log` (all entries carry `org_id`/`client_id`/`post_id` + `method`/`model`; never the API key or prompt text):
1. `AI request answered by NullAiProvider (no vendor call made)` (info) → the binding fell back to `NullAiProvider`; context has `ai_enabled`/`api_key_configured` booleans.
2. `DeepSeek request started` (info) → includes `max_tokens` and `prompt_chars` (size only).
3. Per attempt, exactly one failure line when something goes wrong:
   - `DeepSeek HTTP request threw an exception` (error) — `exception`, scrubbed `error`, `status`, `vendor_error` (DeepSeek's `error.message`, e.g. bad model name). 4xx responses land here because `Http::retry()` throws once its 429/5xx retry window doesn't apply.
   - `DeepSeek request rejected` (warning) — non-2xx that didn't throw; `status`, `vendor_error` or a body excerpt.
   - `DeepSeek returned empty content` (warning) — `finish_reason`, `usage`, `has_reasoning_content`, and a `hint` when `finish_reason = length` (reasoning models can burn all of `max_tokens` before answering).
   - `DeepSeek returned unparsable JSON` (warning) — `json_error`, `content_chars`, `response_excerpt`, `finish_reason`/`usage`, plus the `DEEPSEEK_MAX_TOKENS` hint when truncated.
   - `DeepSeek response failed schema validation` (warning) — precise `reason` from `shapeError()` (e.g. `missing top-level "ideas" key`, `ideas[1].description must be a string, got null`), `top_level_keys`, `response_excerpt`.
4. `DeepSeek request retrying` (warning, with `reason`) then `DeepSeek request failed after retries` (error, with `last_reason`) — or `DeepSeek request completed` (info) with `duration_ms`, `finish_reason`, `usage` (prompt/completion/reasoning/total tokens).
5. `AI guardrail filtered {caption suggestions|post ideas|trends}` — `received`/`kept`/`dropped` counts; logged as a **warning** when items came in but none survived (the guardrail emptied the panel), info otherwise. Individual drops still log `AI guardrail rejected a … for a banned phrase` with the matched `phrase`.
- `response_excerpt` is scrubbed via `AiGuardrailService::scrub()` and capped at 500 chars (head + tail, so a cut-off ending is visible). It contains model *output* only, never the prompt.

## Business Rules
- Controllers must stay thin and delegate to the Step 2.3 Actions above — never call `AiProvider`/`DeepSeekProvider` directly.
- `AiProvider` is the only entry point any Action/controller should depend on — never a concrete provider class — so the vendor can change without touching callers.
- `RecommendPostsAction::persist()` splits inline `#tags` out of the idea's `suggestedCaption`: they go into `Post::hashtags` as bare tags (no `#`, de-duplicated) and are removed from `master_caption` (leftover trailing spaces/blank lines tidied). Ideas have no separate hashtags field — the model writes them into the caption.
- Persisting an AI idea as a draft post, or applying an AI caption suggestion to a post, never happens automatically — both require an explicit, separate call (`RecommendPostsAction::persist()` / `SuggestCaptionsForPostAction::apply()`) triggered by a confirmed user action.
- Every Step 2.3 Action (`DiscoverTrendsAction`, `RecommendPostsAction`, `SuggestCaptionsForPostAction`) runs the provider's raw result through `AiGuardrailService` before returning it — no caller sees an unfiltered suggestion/idea/trend, and the 2h trends cache stores the already-filtered result. Platform policy issues are only ever surfaced as warnings (Step 2.6) — the suggestion text itself is never silently rewritten.

## Configuration
- `config/ai.php` (Step 2.2): `deepseek.api_key` (env `DEEPSEEK_API_KEY`), `deepseek.model` (env `DEEPSEEK_MODEL`, default `deepseek-flash`), `deepseek.base_url` (env `DEEPSEEK_BASE_URL`, default `https://api.deepseek.com`), `deepseek.timeout` (env `DEEPSEEK_TIMEOUT`, default `30`), `deepseek.max_tokens` (env `DEEPSEEK_MAX_TOKENS`, default `1024`) — this budget includes a reasoning model's hidden `reasoning_tokens`, so reasoning models (e.g. `deepseek-v4-pro`) need ≈8000; a truncated attempt's retry doubles it up to 16000.
- `config('ai.daily_briefs')` (Step 2.5): `quiet_hours_start`/`quiet_hours_end` (env `AI_DAILY_BRIEF_QUIET_HOURS_START`/`AI_DAILY_BRIEF_QUIET_HOURS_END`, default `21`/`7`, app-local hours, wraps midnight) — only gates the `DailyBriefReadyMail` send in `GenerateDailyBriefsAction`, never the brief generation/storage itself.
- `config('ai.enabled')` (Step 2.4, env `AI_ENABLED`, default `false`): the platform/environment feature flag. It doubles as half of the Step 2.7 provider-selection condition in `AppServiceProvider` — `AiProvider::class` only resolves to `DeepSeekProvider` when `ai.enabled` is true **and** `ai.deepseek.api_key` is non-empty, otherwise `NullAiProvider`. As of Step M-7.1 no controller/presenter checks this flag directly for its capability gate; they go through `App\Services\AI\AiAccess`, which ANDs the platform flag with the per-org `organizations.ai_enabled` toggle (see "AI control plane" below).
- `config('ai.guardrails.prohibited_topics')` (Step 2.6, env `AI_PROHIBITED_TOPICS`, comma-separated, default empty): global banned-phrase list read by `AiGuardrailService` on top of each client's own `BrandBrain::voice['do_nots']`/`hashtag_policy['never_use']`.
- `Platform::maxHashtags()`/`Platform::allowsLinksInCaption()` (Step 2.6, `app/Enums/Platform.php`): interim per-platform policy constants consumed by `AiGuardrailService::platformWarnings()` for the hashtag-count and in-caption-link checks.

## Tests
- `tests/Unit/Actions/AI/GenerateDailyBriefsActionTest.php` (Step 2.5): mocks `DiscoverTrendsAction`/`RecommendPostsAction` to assert a brief is generated per active client (paused/archived clients skipped), is idempotent for the same client/day (second call makes no further Action calls), and that the notification email is held back — without clearing `notified_at` — during the configured quiet hours window.
- `tests/Feature/GenerateDailyBriefsCommandTest.php` (Step 2.5): runs the `ai:generate-daily-briefs` Artisan command end-to-end against the real container (`NullAiProvider`), asserting one `DailyBrief` per active client and that re-running the command the same day doesn't duplicate it.
- `tests/Feature/DailyBriefControllerTest.php` (Step 2.5): mirrors `AiControllerTest`'s authz matrix (Owner/Strategist only, org-scoped, 404 when `ai.enabled` is false) and asserts the endpoint only ever returns the requested client's own briefs.
- `tests/Unit/Contracts/AiProviderTest.php`: asserts a concrete implementation satisfies the contract and that the container resolves `AiProvider` to `NullAiProvider` by default (`AI_ENABLED=false`/no key in the test environment, returning empty DTO collections), and covers all four Step 2.7 combinations of `ai.enabled` × `ai.deepseek.api_key` presence (only enabled+key resolves `DeepSeekProvider`, every other combination falls back to `NullAiProvider`).
- `tests/Unit/Support/AI/AiDtoTest.php`: verifies DTO construction and JSON serialization shapes for all six DTOs.
- `tests/Unit/Support/AI/ClientContextBuilderTest.php` (Step 2.10): asserts the context includes the client profile and every Brand Brain category, lists only planning/active campaigns with goals, handles a new client with no brand brain/campaigns, scrubs PII typed into the brand brain, and that `fingerprint()` changes when the brand brain does.
- `DeepSeekProviderTest` also asserts (for all three methods) that the system prompt carries the grounding rules plus the client's name/voice/pain points/hashtags/pillars, that captions include the post's campaign, and that ideas/trends carry their method-specific grounding instructions. `DiscoverTrendsActionTest::test_editing_the_brand_brain_bypasses_the_cached_trends` covers the cache fingerprint.
- `tests/Unit/Services/AI/DeepSeekProviderTest.php` (Step 2.2): `Http::fake()`/`Http::fakeSequence()` coverage for all three methods returning parsed DTOs on a valid response, the one-time schema-invalid retry (succeeding on the second attempt and failing closed to an empty DTO when still invalid), an empty DTO on a non-2xx vendor response, and that the API key is never logged.
- `tests/Unit/Actions/AI/DiscoverTrendsActionTest.php`, `RecommendPostsActionTest.php`, `SuggestCaptionsForPostActionTest.php` (Step 2.3): mock `AiProvider` with Mockery to assert each Action delegates with the right arguments, that `DiscoverTrendsAction` caches per client/platform set (including order-independence and per-client isolation), and that `persist()`/`apply()` only ever run when explicitly called — never from the read-only `__invoke()` path.
- `tests/Feature/AI/AiActionsTest.php` (Step 2.3): resolves each Action through the container against a fake `AiProvider` bound via `$this->app->instance()`, exercising the same swap a controller would rely on, and asserts the persisted/applied database state.
- `tests/Feature/AiControllerTest.php` (Step 2.4): asserts each `AiController` endpoint returns the expected JSON shape for an Owner/Strategist in the resource's org, that a `Designer` and a user from a different org are both `403`, that every endpoint is `404` when `config('ai.enabled')` is false, and that the `posts/edit` Inertia props expose `ai.enabled`/`ai.canUse` without leaking the DeepSeek API key.
- `tests/Unit/Services/AI/AiGuardrailServiceTest.php` (Step 2.6): regression coverage asserting a caption suggestion/idea/trend mentioning a brand brain `do_nots`/`never_use` phrase or a globally configured prohibited topic is dropped (never returned) on all three surfaces, that `scrub()` redacts emails/phone numbers/API-key-shaped secrets, and that `platformWarnings()` flags in-caption links and hashtag overages without mutating the suggestion.

## Known Gaps / Open Questions
- Steps 2.1 (contract, DTOs, Null provider, binding), 2.2 (`DeepSeekProvider`, `config/ai.php`), 2.3 (the three Actions), 2.4 (`AiController`, routes, editor/dashboard UI), 2.5 (`DailyBrief`, `GenerateDailyBriefsAction`, nightly schedule, `DailyBriefController`, "Daily Briefs" tab), 2.6 (`AiGuardrailService` banned-phrase filtering/PII scrubbing/platform policy warnings), and the core provider-selection half of 2.7 (`AppServiceProvider` now swaps `AiProvider::class` to `DeepSeekProvider` when `AI_ENABLED=true` and `DEEPSEEK_API_KEY` is set) are implemented. The remaining parts of 2.7 (per-org `organizations.ai_enabled` toggle + Settings UI switch, log-derived metrics) and Steps 2.8–2.12 from
  `.junie/plans/graphy-development-steps.md` — cost controls, feedback loop, deeper context, polish, and standout
  differentiators — are not implemented yet.
- The Brand Persona PDF (`BrandBrain::persona_*`) is **not** fed to the AI yet — only the structured Brand Brain fields are. Doing so needs PDF text extraction (e.g. a `smalot/pdfparser` dependency) plus a cached/truncated text column so the prompt stays within `max_tokens`; deferred.
- The rest of Step 2.10 (best-performing past posts, pillar/campaign usage, calendar gaps, best-time-to-post heuristic) is not in `ClientContextBuilder` yet — it currently covers profile + Brand Brain + campaigns only.
- `GenerateDailyBriefsActionTest::test_it_generates_a_client_scoped_brief_for_every_active_client` is time-of-day dependent: it fails when run inside the configured quiet-hours window (the mail is correctly held back). Pre-existing; the test should freeze time outside the window.
- Trends/ideas are capped at 3 per request (see Changelog), which deliberately undercuts Step 2.13's "5–10 relevant ideas per client" target and means a Daily Brief's "top 5" now holds at most 3 of each. The Trends & Ideas panel still accumulates more over successive Refreshes since rows are upserted by title, never deleted.
- `AiGuardrailService`'s banned-phrase match is a simple case-insensitive substring check (no stemming/fuzzy matching), so a `do_nots` phrase and the generated text must share an exact substring to be caught — e.g. pluralization or rewording can slip through.
- `Platform::maxHashtags()`/`allowsLinksInCaption()`/`captionLimit()` are interim, hand-picked constants (same caveat as the pre-existing `captionLimit()` note) rather than sourced from an authoritative platform policy spec.
- `policyWarnings` (Step 2.6) is only wired into the editor's "Suggest with AI" panel (`posts/edit.tsx`); the "Trends & Ideas" panel's ideas don't currently render platform warnings since `AiPostIdea` isn't tied to a single target platform/caption the way a post's suggestion is.
- The caption-suggestions list in the editor auto-collapses to a one-line summary ("AI suggestions · applied ✓ ... Show suggestions") as soon as any suggestion is applied, to keep the panel from dominating the editor; re-expanding is purely a client-side toggle (`suggestionsExpanded` state), nothing is persisted about which suggestion was shown/hidden.
- The nightly `ai:generate-daily-briefs` run now produces real `trends`/`ideas` whenever `AI_ENABLED=true` and `DEEPSEEK_API_KEY` is configured in that environment; without both, it still runs (idempotent, job/storage/email plumbing is real) but only ever stores empty briefs from `NullAiProvider`.
- The "Daily Briefs" tab's pillar/campaign filters are the same lightweight client-side/server-side substring match used by the Step 2.4 "Trends & Ideas" panel — no structured tagging on `AiPostIdea` yet (see that panel's note below).
- `DeepSeekProvider` is bound against `AiProvider::class` in `AppServiceProvider` whenever `AI_ENABLED=true` and `DEEPSEEK_API_KEY` is non-empty. The container binding stays global per environment (it can't know the org), but the per-org `organizations.ai_enabled` toggle (Step M-7.1) is enforced at every capability surface by `AiAccess`, so an org with AI switched off never reaches a bound provider. See "AI control plane" below.
- `DiscoverTrendsAction`'s cache uses the default `Cache` facade store, which is whatever `CACHE_STORE` is configured for the environment (array in tests); no dedicated AI cache store/tag is configured. It self-heals from a stale/incompatible cached value (see above), but a corrupt cache entry still costs one extra vendor call the first time it's hit after the DTO shape changes.
- The "Trends & Ideas" panel's pillar/campaign filters (Step 2.4) are a lightweight client-side substring filter over each idea's `title`/`description` against the client's `BrandBrain::content_pillars` and campaign names — `AiPostIdea` has no explicit `pillar`/`campaign_id` field, so there's no structured tagging yet; a future step could have `RecommendPostsAction`/the DeepSeek prompt return that explicitly.
- Applying an AI caption suggestion in the editor only ever updates the in-memory `caption`/`hashtags` state (via an explicit "Apply" click, itself gated behind a confirmation dialog if there's existing text) — persisting still goes through the normal `posts.update` Save action, never a dedicated "apply" endpoint; `SuggestCaptionsForPostAction::apply()` remains unused by the controller by design. Applied hashtags are normalized with the shared `normalizeTag()` helper from `components/hashtag-input.tsx` (leading `#`s stripped, empties dropped, de-duplicated), since the AI returns tags like `#demo` but the hashtag field stores bare tags.
- `altText`/`firstComment` from `AiCaptionSuggestion` are only rendered as read-only text in the suggestion card (no `Post` column exists for either yet), matching the Step 2.3 note that they aren't persisted.

## UI Surfaces
- `resources/js/pages/posts/edit.tsx` (Step 2.4): a "Suggest with AI" button next to the master caption field (shown only when `ai.enabled && ai.canUse`) calls `GET posts/{post}/ai/captions`, rendering each `AiCaptionSuggestion` as a card (caption, hashtags, alt text, first comment) with an "Apply" button that fills the caption/hashtags fields — confirming first if the caption already has text — but never auto-saves. (Step 2.6) Each card also renders that suggestion's `policyWarnings` (if any) as a small amber warning list below the content — purely informational, doesn't block "Apply". Once a suggestion is applied the whole list collapses into a one-line "applied ✓" summary with a "Show suggestions" toggle (the applied card stays highlighted when re-expanded) so it stops crowding the rest of the editor.
- `resources/js/pages/clients/show.tsx` (Step 2.4, updated): a "Trends & Ideas" tab (shown only when `ai.enabled && ai.canUse`) with a platform toggle row and a Refresh button that calls `GET clients/{client}/ai/trends` + `GET clients/{client}/ai/ideas` in parallel — which now also persist their results server-side — then reloads only the `ai` Inertia prop so the lists reflect the newly-saved rows (with real ids). The list itself is seeded straight from `ClientController@show`'s `ai.trends`/`ai.ideas` props (not an empty array), so it survives a plain page refresh instead of requiring another Refresh click. Each trend/idea is now a clickable card (`Link` to `ai-trends.show`/`ai-post-ideas.show`) instead of a static block, matching the list → detail pattern used elsewhere in the app; ideas that already have a linked draft `Post` show a "Draft created" badge. Pillar/campaign filter selects still apply client-side over the ideas list. A new "Filter by type" segmented control (All/Trends/Ideas, client-side `aiTypeFilter` state) and a "Filter by channel" `Select` (client-side `aiListPlatformFilter` state, checking each item's `platforms` array) further narrow the combined list, independent from the platform-toggle row that only controls the Refresh query. Trends and ideas are now merged into a single combined, filtered list (sorted trends-then-ideas), each card tagged with a "Trend"/"Idea" `Badge` so the type is still obvious, client-side paginated 5 items per page (Previous/Next buttons + "Page X of Y"), with the page resetting to 1 whenever the underlying trend/idea counts or the type/channel filters change. While a Refresh is in flight, a `Loader2`-spinner overlay covers the list to give visual feedback that the request is loading.
- `resources/js/pages/clients/ai-trend.tsx` (new): standalone detail page for one persisted trend — title, full summary, platform badges, and a simple confidence bar — with a back link to the client's "Trends & Ideas" tab.
- `resources/js/pages/clients/ai-post-idea.tsx` (new): standalone detail page for one persisted idea — title, description, platform badges, suggested caption/posting time — plus a "Turn into draft post" button (`POST ai-post-ideas/{aiPostIdea}/persist`) that swaps to an "Open draft post" link once `post_id` is set. `persistIdea()` now redirects straight to `posts.edit` for the newly-created draft instead of back to the idea's own detail page, so the user lands on the post they just created rather than having to click "Open draft post" themselves.
- `resources/js/pages/clients/show.tsx` (Step 2.5): a "Daily Briefs" tab, gated the same as "Trends & Ideas", with a "Load briefs" button that calls `GET clients/{client}/daily-briefs` and renders each `DailyBrief` (date header, trends/ideas side by side) — read-only, no actions. (Historical daily-brief trends/ideas are still only the inline, non-persisted shape stored in `daily_briefs.trends`/`.ideas` — they don't get their own `AiTrend`/`AiPostIdea` rows or detail pages.)

## AI control plane (Phase M-7.1 — dev plan Steps 2.7–2.9)

Finishes the AI control plane: a per-org enable toggle, token-accurate usage metering, a monthly
budget, and a feedback loop — so AI is safe to sell and meter.

### Capability gate — `App\Services\AI\AiAccess`
- The single source of truth for "may this org/user use AI?". `enabledForOrg(Organization)` =
  `config('ai.enabled')` **AND** `organizations.ai_enabled`; `enabledForUser(?User)` resolves that
  for the user's org; `canUse(?User)` additionally requires `Role::Owner`/`Role::Strategist`;
  `authorize(?User)` is the controller guard (404 when AI is off for the org, 403 for the wrong role).
- Replaces the inline `config('ai.enabled') + role` checks that used to live in `AiController`,
  `DailyBriefController`, `BuildClientPageHeaderAction::canUseAi()`, `BuildCampaignWorkspaceHeaderAction`,
  `PostController::edit` (the `ai` prop + image-studio gate), `AiImageController::guard()`,
  `Campaigns\CampaignAiController::guard()` and `GridPlanPolicy::useAi()`. Turning off either the
  platform flag or the org toggle immediately hides every agency/campaign/grid AI surface for that org
  (dev plan exit 2.13).
- **Scope note:** the business shell (B-* controllers, out of this MVP) still gates on the platform
  flag only; the per-org toggle is wired through the agency product (agency + campaign + grid).
- `organizations.ai_enabled` (migration `2026_11_10_100100`, boolean, **default false** so real orgs
  opt in). `OrganizationFactory` defaults it **true** so the existing AI test suite (which only flips
  the global config) keeps passing; `OrganizationFactory::aiDisabled()` exercises the off path.

### Usage metering — `ai_usage_logs` text path
- `ai_usage_logs` gains `prompt_tokens`/`completion_tokens` (migration `2026_11_10_100100`), so text
  spend is metered per token, not just per call.
- `App\Services\AI\DeepSeekProvider::lastUsage()` exposes the most recent structured call's
  tokens/latency. `App\Services\AI\AiCallMeter` reads it (when the resolved provider is DeepSeek) and
  records one row via `App\Actions\AI\RecordAiUsageAction` (a `App\Support\AI\AiUsage` DTO, wrapped in
  `DB::transaction()`, **never throws** — a logging failure degrades to a `warning`, the AI answer
  still returns). Wired into the three agency text Actions (`SuggestCaptionsForPostAction`,
  `DiscoverTrendsAction`, `RecommendPostsAction`); a cached `DiscoverTrendsAction` result is free and
  records nothing. The pre-existing `AiTextUsage` (B-6) and `CampaignAiUsage` (C-8.3) recorders are
  unchanged and still write their own rows, so there's exactly one row per vendor call per path.

### Monthly budget — `App\Services\AI\AiUsageGuard`
- `organizations.ai_monthly_token_budget` (nullable int, null = unlimited). `ensureWithinBudget()`
  sums this calendar month's prompt+completion tokens for the org (image rows carry no tokens, so
  they don't count) and throws `App\Exceptions\AI\AiBudgetExceededException` when the ceiling is
  reached — surfaced by `AiController` as a `422 {message, budgetExceeded}` the panel shows verbatim
  ("Monthly AI limit reached — contact your admin."), not a generic 500. Checked before each real
  vendor call (inside `DiscoverTrendsAction` only on a cache miss). `summary()` powers the Settings
  panel (tokens used/remaining, requests, est. cost). The budget guards the agency text path; it's an
  allowance ceiling, not a hard per-request billing cap (a single call can tip slightly over).

### Feedback loop — `ai_feedback`
- `ai_feedback` (polymorphic `subject_type`/`subject_id`, `user_id`, `rating`, `reason`; unique per
  user/subject). `App\Enums\AiRating` = `Up`/`Down`/`Applied`. `App\Actions\AI\RecordAiFeedbackAction`
  upserts (idempotent re-rating). `App\Http\Controllers\AiFeedbackController` (`trend`/`idea`/`caption`)
  records explicit thumbs — gated by `AiAccess` + the `view` policy on the owning resource — at routes
  `ai-trends.feedback`, `ai-post-ideas.feedback`, `posts.ai.feedback`. The implicit `Applied` signal is
  written when `AiController::persistIdea()` turns an idea into a draft post. `App\Services\AI\AiPerformanceService::forOrg()`
  aggregates acceptance rate (positive/total), counts and the top thumbs-down reasons for the internal
  Settings view. (A scheduled weekly digest email is deferred — see gaps.)

### Settings → AI usage
- `Settings\AiUsageController::edit` now also returns `textUsage` (budget summary), `performance`
  (acceptance), `settings.{ai_enabled, ai_monthly_token_budget}` and `aiConfigured` (platform flag +
  key). `updateSettings` (route `ai-settings.update`, Owner-only via `OrganizationPolicy::update`)
  saves the toggle + budget via `UpdateAiSettingsAction`. `resources/js/pages/settings/ai-usage.tsx`
  renders the enable toggle, the token-budget field, a text-usage/acceptance `StatStrip`, and the
  existing image quota. `resources/js/components/ai/ai-feedback-buttons.tsx` is the shared thumbs
  control on the trend/idea detail pages.

### Async text jobs (Step M-7.2 — dev plan I-9.2)
- Captions, trends and ideas used to run inside the HTTP request (vendor latency + a schema retry),
  risking a gateway timeout. They now dispatch a queued `App\Jobs\RunAiTextJob` and return a
  `request_id`; the UI polls and can cancel.
- `ai_text_jobs` (`App\Models\AiTextJob`, uuid pk = the request id, client-supplied for idempotent
  dispatch): `kind` (`App\Enums\AiTextJobKind`), `params`, `status` (`App\Enums\AiTextJobStatus`),
  `result` (the exact payload the sync endpoint used to return), `error`. `RunAiTextJob` loads the
  row, calls the same Action, stores the payload; a job cancelled before/while it runs is skipped; a
  failure (incl. over-budget) is recorded as `failed` with a human message.
- `AiController`: `trends`/`ideas`/`captions` now `dispatchTextJob()` (budget-checked synchronously so
  an over-budget org still gets the clean 422, then `firstOrCreate` + `RunAiTextJob::dispatch()->afterCommit()`,
  `202 {request_id, status}`). `jobStatus` (`GET ai-text-jobs/{aiTextJob}`, outside `throttle:ai` so
  2s polling isn't limited) and `cancelJob` (`POST …/cancel`) are gated by `AiAccess` + the `view`
  policy on the job's post/client (so cross-tenant is refused — covered by `CrossTenantRouteTest`).
  `repurpose` stays synchronous (one short call the editor awaits).
- `RunAiTextJob` runs on the dedicated `ai` queue (`Queues::AI`), not `default`, so it never sits
  behind publishing/reports. `php artisan dev` already works every named queue.
- Frontend: `resources/js/lib/ai-text-job.ts` (`runAiTextJob`/`cancelAiTextJob`) dispatches and polls
  — it generates the `request_id` with `resources/js/lib/request-id.ts`'s `requestId()` (never
  `crypto.randomUUID()`, which throws on plain-http `.test` hosts and would hang the call);
  `use-ai-captions.ts` and `ai-insights.ts`'s `requestAiInsights` use it, and the editor shows a
  Cancel button while a run is in flight. Usage rows from async runs carry a null `user_id` (no auth
  in the worker).

### Writing tools (Step M-7.3 — dev plan I-9.4)
- Inline editor tools that rewrite a caption in the brand voice, a human always confirming. Contract
  `App\Contracts\WritingAssistant` (`rewrite(Client, text, WritingTool, ?Platform, ?tone)`) → DeepSeek
  or Null (bound in `AppServiceProvider` on the usual AI-enabled+key rule; Null returns the text
  unchanged). `App\Enums\WritingTool`: Shorten, Expand, ChangeTone, AddCta, Adapt.
- `App\Actions\AI\RewriteCaptionAction`: budget-gated + metered (via `AiCallMeter`), runs the rewrite
  through the caption guardrail (a rewrite that introduces a banned phrase is dropped → original kept)
  and clamps to the platform caption limit when adapting. `POST posts/{post}/ai/rewrite`
  (`AiController::rewrite`, `throttle:ai`).
- **Alt text from image** (multimodal): `App\Actions\AI\GenerateAltTextForAssetAction` reuses the
  vision caption path (`SuggestCaptionsFromMediaAction`, B-6.2 — validation, bytes, guardrail,
  metering) and keeps the alt-text line, budget-gated. `POST posts/{post}/ai/alt-text/{asset}`
  (the asset must belong to the post's client). Needs a configured Gemini vision provider; otherwise
  returns null and the editor says so.
- **Repurpose** (adapt to every platform at once) is Step B-6.4, already shipped; the MVP's
  "adapt to {platform}" at master scope is delivered by Repurpose, with the `Adapt` tool available for
  a single platform via `rewrite`.
- Frontend: `resources/js/components/post-editor/editor/writing-tools-menu.tsx` (the "Writing tools"
  dropdown on the master composer: shorten/longer/tone/CTA) and the per-image "suggest alt text"
  button in `media-details.tsx` (wired from `media-section.tsx`, shown only when AI is usable).

### Inbox reply drafts (Step M-9.3 — the social inbox)
- Contract `App\Contracts\ReplyAssistant` (`draftReply(Client, InboxConversation, ?instruction)`) →
  DeepSeek or Null (bound in `AppServiceProvider` on the same AI-enabled+key rule; Null returns `''`).
  DeepSeek grounds the draft in the client's Brand Brain (`buildSystemPrompt`) and the actual comment text.
- `App\Actions\Inbox\DraftInboxReplyAction`: `AiAccess` gate, `AiUsageGuard` budget, metered via
  `AiCallMeter` as method **`inbox_reply`**, run through the caption guardrail (a draft with a `never_use`
  phrase is dropped → empty, UI falls back to "write your own"). **Returns text only; never sends.**
  `POST inbox/{conversation}/ai/draft` (`throttle:ai`). Sending is the separate human-only
  `SendInboxReplyAction` (see `inbox.md`). Tested: drafting makes zero vendor-reply calls.

### Turn into post & brand-fit score (Step M-7.4 — dev plan Steps 2.11/2.12)
- **Turn into post** was already shipped (Step 2.4): `ai-post-ideas.persist` turns an idea into a
  pre-filled draft in one click (and records the implicit `Applied` feedback, M-7.1).
- **Brand-fit score**: `App\Services\AI\BrandFitScorer` gives each caption suggestion a deterministic
  0–100 score + one-line reason against the Brand Brain (rewards always-use hashtags and content
  pillars, punishes a `never_use`/`do_nots` phrase — such a suggestion scores low here and is also
  dropped entirely by the guardrail before it is shown). No extra vendor call. The captions job adds a
  parallel `brandFit` array to its result; the editor renders a coloured `{score}/100 on-brand` badge
  with the reason on each suggestion card (`ai-caption-suggestions.tsx`). Other Step 2.12
  differentiators (A/B captions, cross-client trend radar, AI Lab, multi-language) are deferred —
  Step 2.12 says ship the clearest-value ones first.

### Honest trends (Step M-7.5 — dev plan I-9.5)
- DeepSeek can't browse, so its "trends" are hypotheses. `AiTrend` DTO + `ai_trends` gain a `source`
  label; the editor/panel show it. `DiscoverTrendsAction` tags the model's trends **"AI hypothesis"**
  (the detail page adds "not live data — a starting point") and merges a real-signal source that
  leads the list.
- `App\Contracts\TrendSource` (swappable: `discover(Client, platforms, limit)`, `label()`) →
  `App\Services\AI\Trends\TopPostsTrendSource` (the client's own best posts via the M-3.1 engagement
  layer, tagged "Your top posts", confidence 1.0; empty when there's no measured engagement) bound in
  `AppServiceProvider`; `NullTrendSource` is the no-signal default. External trend APIs (Google
  Trends, TikTok Creative Center) can slot behind the same contract later.
- The interim cap of 3 is lifted to `ai.suggestions.per_request` (default 6, clamped 1–10), applied to
  trends and ideas; the merged trend list takes up to half from real signal, the rest model, capped.
  `AiGuardrailService::filterTrends` preserves the `source` when scrubbing.

### Tests
- `tests/Unit/Services/AI/AiAccessTest.php`, `AiUsageGuardTest.php`, `AiPerformanceServiceTest.php`,
  `BrandFitScorerTest.php`; `tests/Unit/Actions/AI/HonestTrendsTest.php` (model labelled a hypothesis,
  real signal leads + is stored with its source, cap, default source is top posts);
  `tests/Feature/AI/AiWritingToolsTest.php` (rewrite meter/budget/authz/404,
  adapt clamps to the platform limit, alt text from a faked vision call, foreign asset refused);
  `tests/Feature/AI/AiBrandFitTest.php` (a `never_use` suggestion is filtered out, the on-brand one is
  scored);
  `tests/Feature/AI/AiControlPlaneTest.php` (org toggle hides endpoints + editor props, token-accurate
  metering, budget 422, feedback authz + idempotency, implicit Applied, Settings authz + persist);
  `tests/Feature/AI/AiAsyncTest.php` (dispatch returns immediately without running the provider,
  idempotent request id, worker fills the polled result, cancel is honoured); `AiControllerTest`
  covers the dispatch → poll flow.

## AI Image Studio (Phase 16)

Turns an `AiPostIdea` (or a draft `Post`) into a small set of on-brand images the team picks from,
refines and attaches. AI drafts; a human always picks. Lives on the idea page and below the post
editor.

### Providers (verified against Google's docs 2026-10-17)
| Tier | Model | Size | Est. cost / image |
|---|---|---|---|
| Draft | `gemini-3.1-flash-image` (Nano Banana 2) | 1K (e.g. 928×1152 at 4:5) | $0.067 |
| Final | `gemini-3-pro-image` (Nano Banana Pro) | 2K (same price as 1K on Pro) | $0.134 |
| Headline designs (optional) | OpenAI `gpt-image-2` (`OPENAI_IMAGE_MODEL`) | 1024/1536 | configurable |
- A live 2-image draft set measured ~9s per image at the vendor; the DeepSeek brief adds a few
  seconds (`AI_IMAGE_BRIEF_MODEL=deepseek-flash`, a non-reasoning model, keeps it fast). With an
  `ai-images` worker pool of ≥4 processes a 4-image set lands in ~10–15s; one worker renders them
  sequentially (~40s).
- Gemini outputs carry an invisible SynthID watermark and C2PA Content Credentials; we keep the vendor
  original as the asset (previews are separate WebP copies) so the credentials survive to LinkedIn.

### Contract, DTOs, binding (Step 16.1)
- `App\Contracts\ImageProvider`: `generate(AiImageBrief, ImageRenderOptions)`, `edit(GeneratedImage|ReferenceImage, string, ImageRenderOptions)`, `supports(ImageCapability)`, `name()`, `model(ImageQualityTier)`, `costEstimate(ImageQualityTier)`. Failures throw `App\Exceptions\AI\ImageProviderException` subclasses (`ImageSafetyBlockedException`, `ImageProviderTimeoutException`, `ImageProviderUnavailableException`), each exposing an `ImageErrorCode` with one friendly user message.
- DTOs in `App\Support\AI\Images\`: `AiImageBrief` (subject, composition, mood, palette `{name, hex}[]`, style, `textOverlay`, `avoid[]`, `referenceAssetIds[]`; tolerant `fromArray()`), `ImageRenderOptions` (tier, ratio, seed, references, log context), `ReferenceImage`, `GeneratedImage`, `ImageBriefInput`, `GenerateImageSetData`.
- Enums: `ImageAspectRatio` (`1:1`, `4:5`, `9:16`, `16:9`, `1.91:1` rendered at 16:9; `forPlatform()`: Instagram 4:5, TikTok 9:16, LinkedIn/Facebook 1:1), `ImageQualityTier`, `ImageCapability`, `ImageGenerationStatus`, `ImageVariantStatus`, `ImageVariantKind` (generate/refine/regenerate/final), `ImageErrorCode`, `ReferenceImageRole` (logo/style/product + internal `draft`).
- `AppServiceProvider` binds `ImageProvider` to `GeminiImageProvider`/`OpenAiImageProvider` only when `ai.enabled` AND that provider's key is set; otherwise `NullImageProvider` (deterministic solid brand-tone PNG of the requested ratio, no vendor call).
- `App\Support\AI\Images\ImagePromptRenderer` turns a brief into one ordered prompt (reference roles → subject → composition → style → mood → palette → text rule → avoid, always adding "real public figures / third-party logos"). Shared by both adapters.

### Brief: DeepSeek as art director (Step 16.5)
- `AiProvider::draftImageBrief(Client, ImageBriefInput)`. `DeepSeekProvider` asks for strict JSON (subject/composition/mood/style/avoid) with the existing one-retry + `shapeError` path, using `ai.images.brief_model`/`brief_max_tokens`; on failure it falls back to the brand-derived brief. `NullAiProvider` returns the brand-derived brief.
- `App\Support\AI\Images\BrandImageBriefFactory`: `defaults()` (no AI: idea title/description, or a post's whole `master_caption` as the subject, Brand Brain tone/imagery style/palette) and `finalize()`, which always applies what is never left to a model: the person's overrides, palette from `visual.color_palette` minus excluded colours, the headline switch (`textOverlay` null unless on), real reference asset ids, `logo_usage_notes` (only when a Logo reference exists) and `voice.do_nots` → `avoid[]`. Hashtags are stripped everywhere. The subject has no length cap (neither in `GenerateImagesRequest` nor in the studio textarea, which grows to fit, up to `max-h-96` before it scrolls); mood/style/composition overrides are still cut at 400 chars.
- `AiGuardrailService::filterImageBrief()`: cuts every banned phrase (do-nots, `never_use`, prohibited topics) out of the descriptive fields, keeps the brand's do-nots in `avoid[]` but drops `never_use`/prohibited topics from it, PII-scrubs everything, logs only matched phrases.
- `App\Actions\AI\Images\DraftImageBriefAction` caches the filtered brief 24h under `ai:image-brief:{client}:{brief_hash}`, where `brief_hash` = sha256(input + `ClientContextBuilder::fingerprint()`). The fingerprint now also covers Brand Brain reference ids/roles/positions.

### Orchestration (Steps 16.7–16.8)
- `GenerateImageSetAction`: idempotent on the client-generated `request_id` (unique column), per-org limiter `ai-images:org:{id}` (`ai.images.sets_per_minute`), `ImageQuotaGuard`, then one transaction creating the `AiImageGeneration` + N queued `AiImageVariant`s (distinct seeds); dispatches `PrepareImageGenerationJob` after commit and returns immediately.
- `PrepareImageGenerationJob` → `PrepareImageGenerationAction`: drafts/fetches the brief once, stores it, then `Bus::batch()` of `GenerateImageVariantJob`s on the `ai-images` queue (`finally` → `RecomputeImageGenerationStatusAction`).
- `GenerateImageVariantJob` (`ShouldBeUnique` per variant, tries 2, timeout 120) → `RenderImageVariantAction`: atomic claim (pending + no asset) so retries never pay twice; generate/regenerate render the brief with brand references (`BrandReferenceLoader`, logos first, ≤`max_references`); refine calls `edit()` on the parent image; final re-renders at the final tier with the chosen draft as the lead reference. Stores the vendor original via `AssetStorage::storeContents()` (`ai-images/{org}/{client}/…`) plus a WebP preview (`…/previews/`), then in one transaction creates the `Asset` (`source = ai`, `is_ai_generated`, `variant_group_id` = set uuid, `ai_image_variant_id`), marks the variant ready and writes an `ai_usage_logs` row. A provider exception marks the variant failed with its `error_code` and a zero-cost failed usage row (not rethrown).
- `CreateFollowUpVariantAction` backs `RefineImageVariantAction` (instruction guardrailed), `RegenerateImageVariantAction` (new seed, discards the old tile) and `FinalizeImageVariantAction` (draft → final once; marks `selected_at`). Each counts one image against the quota and is idempotent per request id.
- `DiscardImageVariantAction` hides/restores tiles. `UseGeneratedImagesInPostAction` resolves the set's post (creating the idea's draft through `RecommendPostsAction::persist()`), then `AttachGeneratedImagesToPostAction` (see posts.md).

### Models / Tables
- `ai_image_generations` (`AiImageGeneration`): `uuid`, `org_id`, `client_id`, `ai_post_idea_id`, `post_id`, `requested_by`, `request_id` (unique), `brief` (json), `art_direction` (json, what the person typed), `brief_hash`, `provider`, `model`, `quality_tier`, `aspect_ratio`, `variant_count`, `status`, `cost_estimate`. Index `(client_id, created_at)`.
- `ai_image_variants` (`AiImageVariant`): `generation_id`, `org_id` (denormalised for the quota count), `position`, `kind`, `status`, `asset_id`, `parent_variant_id`, `quality_tier`, `idempotency_key` (unique), `seed`, `instruction`, `preview_disk/path`, `provider_request_id`, `duration_ms`, `error_code`, `cost_estimate`, `selected_at`, `discarded_at`.
- `ai_usage_logs` (`AiUsageLog`, built here ahead of Step 2.8): one row per vendor call, `method = image.generate|image.edit`, `succeeded`, `error_code`, `cost_estimate`, `duration_ms`.
- `organizations.ai_monthly_image_quota` (null = unlimited).
- Policy `AiImageGenerationPolicy`: Owner/Strategist generate + manage; Designer views/downloads; ClientReviewer/Viewer nothing; always org-scoped.

### Routes / Controllers (`AiImageController`, all 404 when `ai.enabled` is off)
- `POST ai-post-ideas/{aiPostIdea}/images` → `generateForIdea` · `POST clients/{client}/ai/images` → `generate` (optional `post_id` of that client)
- `GET ai-image-generations/{generation}` → `show` (lean JSON for polling) · `POST ai-image-generations/{generation}/use` → `useInPost` (redirects to `posts.edit`)
- `POST ai-image-variants/{variant}/refine|regenerate|finalize|discard` · `GET ai-image-variants/{variant}/download`
- Props: `AiController::showIdea` and `PostController::edit` add a lazy `imageStudio` prop from `App\Support\AI\Images\ImageStudioPresenter` (formats, default format, quota, brand defaults, palette, references, last 8 sets). It never contains the full brief, avoid list, provider/model, costs or keys.
- Quota/rate-limit refusals are `ImageStudioException`s that render themselves as the `imageStudio` validation error.

### Configuration (`config('ai.images')`)
- `AI_IMAGE_PROVIDER` (`gemini|openai|null`, default `null`), `GEMINI_API_KEY`, `GEMINI_IMAGE_DRAFT_MODEL`/`FINAL_MODEL` (+ `_SIZE`, `_COST`), `AI_IMAGE_TEXT_PROVIDER` + `OPENAI_API_KEY`/`OPENAI_IMAGE_MODEL`, `AI_IMAGE_BRIEF_MODEL` (DeepSeek model for briefs; falls back to `DEEPSEEK_MODEL`), `AI_IMAGE_TIMEOUT` (90), `AI_IMAGE_MAX_VARIANTS` (4), `AI_IMAGE_MAX_REFERENCES` (8), `AI_IMAGE_QUEUE` (`ai-images`), `AI_IMAGE_SETS_PER_MINUTE` (10), `AI_IMAGE_PRUNE_AFTER_DAYS` (30). `phpunit.xml` forces the null provider and empty keys.

### Settings (Step 16.11)
- `GET/PUT settings/ai-usage` → `Settings\AiUsageController` (Owner only via `OrganizationPolicy::update`): `GetAiImageUsageAction` (images vs quota, finals, est. cost, failed) as a `StatStrip`, and `UpdateAiImageQuotaAction`.
- `ImageQuotaGuard`: counts this calendar month's non-failed variants (drafts, refinements and finals each count one).

### UI (Step 16.9) — `resources/js/components/image-studio/`
- `image-studio.tsx`: "IMAGE STUDIO / Make it visual." header with the caption as a collapsible quote; Art direction panel (subject/mood/style prefilled from the latest brief or brand defaults, palette swatches to exclude colours, "Add headline text" with an /80 counter, reference thumbnails with role tags, "Reset to brand"); Format & size (teal-pill toggles, preselected from the idea's first platform; 2/4 images); one Primary "Generate images"/"Generate again" with a mono quota line; results grid (2 cols mobile / 4 desktop via container queries) with skeleton "Drafting…" tiles; earlier sets as ruled collapsible rows; sticky selection bar ("Make final" / "Use in post"); friendly errors.
- `image-tile.tsx` (hover/focus actions, always visible on touch: Select, Refine with quick chips, Regenerate, Open, Download, Hide), `image-lightbox.tsx` (the image shown only inside `SocialPostPreview` with the real caption — no separate raw-image panel; ←/→ arrows flank the preview, Enter selects, R refines), `use-generation-polling.ts` (polls the lean status endpoint every 2s only while a set is pending and the tab is visible, then reloads `imageStudio` once), `studio-actions.ts` (client `request_id`s that work on plain-http `.test` hosts).
- Status tones in `lib/status.ts`: `imageGeneration`, `imageVariant`.

### Logging / Diagnostics
- `AI image set requested` → `AI image brief drafted|served from cache` → `AI image set dispatched` → per variant `Image provider routed`, `Gemini image request started/completed` (model, tier, ratio, reference_count, duration_ms, cost_estimate, provider_request_id) or `… retrying` (warning) / `… blocked on safety grounds` (warning) / `… failed` (error, with Google's status name only) → `AI image variant ready|failed` → `AI image set settled`. Never logged: prompts, brief text, image bytes, vendor message text, keys.
- Tiles stuck on "Drafting…" mean no worker is consuming `ai-images`: run `php artisan queue:work --queue=ai-images,default` (or a dedicated `--queue=ai-images` pool).

### Tests
- `tests/Feature/AI/AiImageStudioTest.php` (happy path 4→4 assets, partial failure, double submit, job retry, queue, rate limit, quota, refine/regenerate/final lineage + tiers, follow-up quota, use in post, status unchanged, cross-client post, Designer read-only, ClientReviewer/Viewer denied everywhere, other org, feature flag, props without internals/keys, polling endpoint, discard, headline validation, post subject prefilled with the whole caption, >400-char subject accepted and kept whole).
- `tests/Feature/AI/BrandReferencesTest.php`, `tests/Feature/AI/ImageStudioHousekeepingTest.php` (prune, AI label, portal scoping, AI usage settings).
- `tests/Unit/Services/AI/Images/GeminiImageProviderTest.php` (request shape per tier, ratio, references, safety mapping, 429 retry, 5xx give-up, 400, timeout, log leak test), `ImageProviderRouterTest.php` (routing + OpenAI shape/moderation), `ImageQuotaGuardTest.php`, `ImageStorageTest.php` (incl. `dominantColours()` on a real JPEG — regression for the v3 `convertTo()` class-name bug); `tests/Unit/Contracts/ImageProviderTest.php` (binding matrix, Null provider dimensions); `tests/Unit/Support/AI/Images/ImageDtoTest.php`; `tests/Unit/Actions/AI/DraftImageBriefActionTest.php` (schema retry, fallback, guardrails, hashtags, cache, overrides).

### Known gaps
- `GPT Image 2`'s model id/pricing weren't verifiable at build time; `OPENAI_IMAGE_MODEL`/costs are config, and the adapter is only covered by HTTP-faked tests. It is off unless `AI_IMAGE_TEXT_PROVIDER=openai` and `OPENAI_API_KEY` are set.
- Polling uses the lean JSON endpoint instead of Inertia `usePoll`, so the post editor (a heavy page) isn't re-rendered every 2s.
- The studio isn't wired into the read-only preview mode, and there is no per-org AI toggle yet (Step 2.7 gap).
- 1.91:1 renders at 16:9 (no native vendor ratio); the feed crops lightly.
- Step 16.13's "note SynthID in the org terms copy" is open: the app has no terms/ToS page to add it to yet.
- Light/dark, mobile/desktop visual check of the studio is still to be done in a browser.
- Selections spanning several sets attach to the post resolved from the first selected set (all sets of an idea/post resolve to the same post).

## Local & seasonal idea engine (Step B-6.1)

Tunes the business shell's idea generation to a brand's place and moment. Kept off the agency
`AiProvider::recommendPosts()` path so nothing agency-side (or its many test doubles) changes.

- **`App\Contracts\EventCalendar`** → `App\Services\Events\StaticEventCalendar` (bound singleton): the
  source of upcoming public holidays and awareness days. A curated static dataset (launch countries
  **GB** and **US**, plus a global set every brand gets — open question §5.5), each entry resolving
  its own date per year: `fixed` (month/day), `nth` (nth/last weekday of a month) and `easter`
  (offset from Easter Sunday via the Anonymous Gregorian algorithm — no `ext-calendar`, so Pancake
  Day/Good Friday are correct every year and in the SQLite test run). `upcoming(country, from, days)`
  returns `App\Support\Events\EventOccurrence[]` (soonest first; a null country = global only). A
  resolved calendar year is cached as plain arrays (`ai.ideas.event_cache_ttl_hours`), rehydrated to
  DTOs on read so a serializing cache store can't hand back an incomplete object.
- **`App\Services\AI\IdeaContextBuilder`** → `App\Support\AI\IdeaContext`: builds the grounding — the
  brand's timezone (org `default_timezone`), location, country (from `clients.countries`, then the
  free-text `location`, then `ai.ideas.default_country`), season (hemisphere-aware), the next
  `ai.ideas.event_window_days` of local events, its content pillars, and recent idea/post titles so
  the model avoids repeats. `IdeaContext::toPromptBlock()` renders it (scrubbed by the provider).
- **`App\Contracts\LocalIdeaProvider`** (`recommendLocalPosts(Client, IdeaContext)`) → DeepSeek + Null
  (bound like `GridAiProvider`: DeepSeek only when `ai.enabled` + key, else Null). DeepSeek reuses the
  grounded system prompt and `requestStructured()`, adding the scrubbed context block and an
  instruction to tie an idea to an upcoming date/season *only where it fits naturally*.
- **`App\Actions\AI\GenerateBrandIdeasAction`**: the single business entry — builds the context, calls
  the provider, guardrails+persists through `RecommendPostsAction::filterAndPersist()` (the shared
  step extracted here), and records one `ai_usage_logs` row via `App\Services\AI\AiTextUsage` (the
  client-scoped counterpart of `CampaignAiUsage`, what B-9.2 will meter). Behind the `/ideas` "More
  ideas" button (`Business\IdeaController::generate`).
- **`App\Actions\AI\GenerateDailyIdeaAction`**: the Today dashboard's "Idea of the day", cached once
  per brand per **local** calendar day. `cached()` only ever reads (the dashboard never triggers a
  vendor call); the `business:generate-daily-ideas` command (hourly, `withoutOverlapping()`/
  `onOneServer()`, registered in `SchedulerHeartbeat::COMMANDS`) fills it at `ai.ideas.daily_hour`
  (6) in each brand's timezone, skipping suspended orgs and no-opping when AI is off.
- Tests: `tests/Unit/Services/Events/StaticEventCalendarTest.php`,
  `tests/Unit/Services/AI/IdeaContextBuilderTest.php`, `tests/Feature/Business/DailyIdeaTest.php`.

## Caption from a photo (Step B-6.2)

- **`App\Contracts\VisionProvider`** (`captionsFromImage(Client, VisionImage, platforms)`) → Gemini
  or Null (bound on `ai.enabled` + `ai.vision.provider === 'gemini'` + key; open question §5.4 picks
  Gemini, the project's image vendor). `App\Services\AI\GeminiVisionProvider` posts to the same
  `models/{model}:generateContent` surface the Image Studio uses, with the photo as an inline image
  part, the Brand Brain context as the `systemInstruction`, the target platforms + caption limits in
  the user prompt, and `responseMimeType: application/json` for strict JSON. It **never throws** — a
  non-2xx, unparsable JSON, an unsupported MIME or any exception is logged and returns empty
  suggestions (an empty caption box), per the plan. `NullVisionProvider` is the AI-off default.
- **`App\Actions\AI\SuggestCaptionsFromMediaAction`**: loads the brand's image asset bytes via
  `AssetStorage` (image-only, size-capped by `ai.vision.max_image_bytes`), calls the provider with the
  brand's connected platforms (so hashtags suit the destinations), guardrails via
  `AiGuardrailService::filterCaptionSuggestions()`, and records one `ai_usage_logs` row
  (`AiTextUsage`, now provider/model/cost-parameterised so it serves both Gemini vision and DeepSeek
  ideas). Nothing is persisted — the user picks.
- Surface: the business `/media` library — a "Caption from photo" action on image tiles opens a
  dialog of three copyable suggestions (caption + hashtags + alt text). `POST /media/{asset}/captions`
  (`business.media.captions`, `throttle:ai`, 404 when AI off, Owner/Manager/Creator only, 404 for a
  foreign asset). Config: `config('ai.vision')` (`provider`, `model`, key/base_url default to the
  `GEMINI_*` env, `timeout`, `max_image_bytes`).
- Tests: `tests/Unit/Services/AI/GeminiVisionProviderTest.php`,
  `tests/Feature/Business/CaptionFromPhotoTest.php`.

## Plan my week (Step B-6.3)

- **`App\Actions\AI\GenerateWeeklyPlanAction`**: fills the posting goal's gaps (via
  `PostingGoalGapService`) across the next 7 days with draft **plan items** — `ai_post_ideas` rows
  marked with a `planned_for` local date (new nullable column) so generation is idempotent (a day
  with a scheduled post or an un-accepted plan item is skipped) and the plan lists itself cleanly.
  Each item carries a best-time slot (`suggested_post_at`): the brand's own engagement via
  `App\Services\Analytics\BestTimeService` where there's enough of it, else `ai.plan.default_hour` in
  the brand's timezone. Content is grounded by the `LocalIdeaProvider` (round-robin over the few ideas
  returned, with local-event/pillar fallbacks so it's useful with AI off), guardrailed and metered
  (`AiTextUsage`, method `generateWeeklyPlan`). One transaction; nothing is scheduled — accepted items
  become `Idea` drafts.
- **`App\Actions\AI\AcceptWeeklyPlanItemsAction`** turns chosen items into draft posts (reusing
  `RecommendPostsAction::persist()`, which stamps `post_id` so they leave the plan);
  **`DiscardWeeklyPlanItemsAction`** deletes them. Both brand-scoped, un-accepted items only.
- Surface: `/ideas` — a "Plan my week" button (shown when a goal is set) and a "This week's plan"
  panel with per-item and bulk Add/Discard. `POST /plan/generate` (`throttle:ai`, 404 when AI off),
  `POST /plan/accept`, `POST /plan/discard` (`business.plan.*`, Owner/Manager). `currentPlan()` sends
  a brand-local `slotLabel` so the page needn't know the timezone.
- Tests: `tests/Feature/Business/WeeklyPlanTest.php`.

## Repurpose across platforms (Step B-6.4)

- **`App\Contracts\CaptionRepurposer`** (`repurpose(Post, platforms)`) → DeepSeek or Null (bound like
  `LocalIdeaProvider`). DeepSeek rewrites the master caption natively per platform (TikTok hook,
  Instagram caption + hashtags, longer-form LinkedIn/Facebook, one-line X), strict JSON with the
  per-platform caption limits/hashtag norms in the prompt.
- **`App\Actions\AI\RepurposeCaptionsForPostAction`**: platforms from the post's targets, one variant
  each, then two guarantees the provider can't be trusted for — guardrail (drop banned-phrase
  variants, scrub) and **clamp** every caption to `Platform::captionLimit()` and hashtags to
  `maxHashtags()` (I-3.3), so a variant never exceeds the platform. Metered via `AiTextUsage`
  (`repurpose`); nothing persisted.
- Surface: `POST posts/{post}/ai/repurpose` (`posts.ai.repurpose`, shared `AiController`, same gate
  as captions). The editor's compose section shows a "Repurpose per platform" button (≥2 platforms)
  whose result fills each channel's per-platform override (I-3.1) in unsaved editor state via
  `editor.setChannel` (`use-ai-repurpose.ts`) — saved only when the post is saved, with a confirm
  when channels were already customised.
- Tests: `tests/Feature/AI/RepurposeCaptionsTest.php` (limit clamping, per-platform variants, banned
  drop, endpoint authz + flag).

## Image Studio presets (Step B-6.5)

- The business shell reuses the **Phase 16 Image Studio engine unchanged** — no new generation,
  rendering or quota code. A preset is only a *starting point* that pre-fills art direction.
- **`App\Enums\BusinessImagePreset`** (`todays_special`, `opening_hours`, `sale_announcement`,
  `quote_card`): the content source — label/tagline/field copy/`defaultFormat` and `artDirection()`
  (per-preset `subject`/`composition`, plus `style` for the three typographic cards so a text card is
  not rendered as a photograph; the photographic "Today's special" leaves `style` unset so the
  brand's own imagery style wins).
- **`App\Support\AI\Images\BusinessImagePresetComposer`**: `compose(Client, preset, text, ?ratio,
  count, requestId)` → a validated `GenerateImageSetData` with `withHeadline` always on, the headline
  clamped to 80 chars, and the variant count clamped to `ai.images.max_variants`. The brand's palette
  and typography are layered on later by `BrandImageBriefFactory` (never invented here). Unit-tested.
- **`App\Support\AI\Images\BusinessImageStudioPresenter`**: builds the `imageStudio` Inertia prop —
  the presets, formats, monthly quota (`ImageQuotaGuard::summary`) and the brand's **standalone**
  preset sets (generations with no `ai_post_idea_id`/`post_id`), each serialised through the shared
  `ImageStudioPresenter::generation()` so a business set leaks no brief/cost/provider/keys.
- Surface: `App\Http\Controllers\Business\ImageStudioController` — `GET /studio`
  (`business.studio.index`, authorised `viewAnyForClient`) renders `business/studio`; `POST
  /studio/generate` (`business.studio.generate`, `throttle:ai-images`, authorised `generate`)
  composes and runs the set through `GenerateImageSetAction`, so the per-org rate limit and monthly
  quota are enforced exactly as the agency studio's are. The brand-agnostic `ai-image-*` routes
  (poll/use-in-post/refine/regenerate/finalise/discard/download) are shared, so `business/studio.tsx`
  reuses the agency `ImageTile`/`ImageLightbox`/`useGenerationPolling`/`studioActions` components with
  a preset-card + single-field dialog in place of the art-direction panel.
- Tests: `tests/Unit/Support/AI/Images/BusinessImagePresetComposerTest.php` (every preset → a valid
  request; headline/variant clamping; ratio override; card-vs-photo style) and
  `tests/Feature/Business/ImageStudioPresetTest.php` (owner generates a standalone set, quota
  enforced, validation, Creator read-only, Viewer denied, standalone-only listing, 404 when AI off).

## Plain-language insight takeaways (Step B-8.2)

- **`App\Contracts\InsightsSummarizer`** (bound DeepSeek/Null in `AppServiceProvider` like
  `LocalIdeaProvider`, so the agency `AiProvider` doubles are untouched): `summarizeInsights(Client,
  array $facts): list<string>` — up to three short, concrete, non-jargon sentences grounded only in
  the brand's own numbers. DeepSeek uses strict-JSON (`{"takeaways": string[]}`); Null returns `[]`.
- **`App\Actions\AI\SummarizeInsightsAction`**: builds compact facts from the Insights payload (never
  raw rows), calls the provider, PII-scrubs each line (`AiGuardrailService::scrub`), and caches the
  result once per brand per ISO week (brand timezone). Records one `ai_usage_logs` row per generation
  via `AiTextUsage` (metered for B-9.2); an empty summary is never cached (retried next view).
  Consumed by the `/insights` deferred `takeaways` panel and the weekly digest (both share the cache).
- Tests: `tests/Feature/Business/InsightsTakeawaysTest.php`.

## Changelog
- 2026-10-23: Step B-8.2 — `InsightsSummarizer` contract (DeepSeek/Null) + `SummarizeInsightsAction`
  (weekly-cached, PII-scrubbed, metered takeaways) behind the `/insights` panel and the weekly digest.
- 2026-10-23: Step B-6.5 — business Image Studio presets: `BusinessImagePreset` +
  `BusinessImagePresetComposer` + `BusinessImageStudioPresenter`, the `business.studio.*` routes and
  the `business/studio` page, all reusing the Phase 16 engine (quota/rate-limit via
  `GenerateImageSetAction`). No schema or engine changes.
- 2026-10-23: Step B-6.4 — "repurpose across platforms": the `CaptionRepurposer` contract +
  DeepSeek/Null, `RepurposeCaptionsForPostAction` (guardrail + per-platform limit clamp), the
  `posts.ai.repurpose` endpoint and the editor "Repurpose per platform" button.
- 2026-10-23: Step B-6.3 — "Plan my week": `GenerateWeeklyPlanAction` +
  `AcceptWeeklyPlanItemsAction`/`DiscardWeeklyPlanItemsAction`, the `/ideas` plan panel + the
  `business.plan.*` endpoints, and the `ai_post_ideas.planned_for` column. New config:
  `ai.plan.default_hour`.
- 2026-10-23: Step B-6.2 — "caption from a photo": the `VisionProvider` contract + Gemini/Null impls,
  `SuggestCaptionsFromMediaAction`, the `/media` caption dialog + `business.media.captions` endpoint,
  and `AiTextUsage` generalised to take provider/model/cost. New config: `ai.vision.*`.
- 2026-10-23: Step B-6.1 — the local/seasonal idea engine (see "Local & seasonal idea engine"):
  `EventCalendar`/`StaticEventCalendar`, `IdeaContextBuilder`/`IdeaContext`, the `LocalIdeaProvider`
  contract (DeepSeek/Null), `GenerateBrandIdeasAction` + `GenerateDailyIdeaAction` + the
  `business:generate-daily-ideas` command, `AiTextUsage`, and the Today "Idea of the day" panel.
  `RecommendPostsAction` gained a public `filterAndPersist()`; the two DeepSeek idea `collect()` calls
  were cast to `(array)` (fewer PHPStan template-type warnings). New config: `ai.ideas.*`.
- 2026-10-20: Phase 18 Step 18.12 — `App\Contracts\GridAiProvider` (DeepSeek / Null, same binding rule): caption ideas per grid tile and a balance check against the Brand Brain palette and pillars (dominant colours worked out locally by `ImageNormalizer::dominantColours()`, no vision call). `GridAiAssist` filters through the guardrail, logs one `ai_usage_logs` row per call and writes nothing until "Use". "Fill this slot" runs a 3:4 Image Studio set — new `ImageAspectRatio::Classic_3_4` (Gemini native, OpenAI 1024×1536). Tests: `GridAiAssistTest`.
- 2026-10-19: Fixed "Attempted to lazy load [socialAccount] on model [PostTarget]" on the editor's "Suggest with AI" (`posts/{post}/ai/captions`) for posts with more than one target: `Post::targetPlatforms()` now `loadMissing('targets.socialAccount')` itself, which also covers its other callers (`DeepSeekProvider` caption prompt, `EvaluatePostChecklistAction`, `ImageBriefInputResolver`). Regression test: `AiControllerTest::test_captions_endpoint_works_for_a_post_with_multiple_targets`.
- 2026-10-18: Campaigns enhancement C-8.3 — `ai.deepseek.estimated_cost_per_call` (`DEEPSEEK_ESTIMATED_COST_PER_CALL`, default 0.002 USD) is recorded as `cost_estimate` on successful DeepSeek campaign calls.
- 2026-10-18: Campaigns enhancement C-2.2 / C-3.3 / C-7.3 — new `App\Contracts\CampaignAiProvider` (brief draft, deliverable plan, wrap commentary) implemented by `DeepSeekProvider` and `NullAiProvider`, bound in `CampaignServiceProvider`. Nothing persists until a person accepts; banned phrases are flagged via `AiGuardrailService::bannedPhrasesIn()`, not dropped; each call logs one `ai_usage_logs` row with `campaign_id`. `ClientContextBuilder::describeCampaignBrief()` feeds an approved brief into caption prompts.
- 2026-10-18: Step I-1.8 — `GenerateDailyBriefsAction` walks clients with `lazyById(100)`; the
  nightly command is overlap-protected and single-server with a heartbeat (see `super-admin.md`).
- 2026-10-18: Step I-1.7 — `clients/{client}/ai/trends`, `clients/{client}/ai/ideas` and `posts/{post}/ai/captions` are now **POST** (they call a paid vendor and upsert rows; a GET could be prefetched, crawled or replayed — GET now returns 405) and throttled. The client page and editor send them with the XSRF header; trends take `platforms` in the body. Named limiters live in `App\Support\RateLimits` (registered in `AppServiceProvider::boot()`):
  `ai` (10/min per user + 60/min per org — trends, ideas, captions), `ai-images` (6/min user +
  30/min org — Image Studio generate/refine/regenerate), `music-search` (30/min per user),
  `exports` (10/min per user — invoice/analytics/report CSV+PDF exports and invoice PDF
  generation), `public-token` (20/min per IP + token and 60/min per IP — review links, client and
  staff invites) and `webhooks` (120/min per IP). All answer with one structured 429
  (`{message, retry_after}` + `Retry-After`; Inertia visits get the message as an error toast).
  Tests: `tests/Feature/Security/RateLimitingTest.php`.
- 2026-10-17: Phase 15 — `GenerateDailyBriefsAction` skips clients of suspended organizations (no AI
  spend while an org is locked out); AI image spend is reported platform-wide in the super admin
  analytics. See `super-admin.md`.
- 2026-10-14: Added `App\Models\DailyBrief` (migration + factory), `App\Actions\AI\GenerateDailyBriefsAction` (nightly, idempotent per client/day, quiet-hours-aware emailing of Owners/Strategists via `DailyBriefReadyMail`), the `ai:generate-daily-briefs` command (scheduled daily at 06:00 in `routes/console.php`), `App\Http\Controllers\DailyBriefController` + `clients.daily-briefs.index` route, `config('ai.daily_briefs')` quiet hours, the Clients module's "Daily Briefs" tab, and unit/feature tests covering idempotency, quiet hours, and authz (Step 2.5).
- 2026-10-14: Added `AiProvider` contract, DTOs, `NullAiProvider` default binding, and unit tests (Step 2.1).
- 2026-10-14: Added `App\Services\AI\DeepSeekProvider`, `config/ai.php`, and unit tests with HTTP fakes covering schema validation/retry/failure/logging (Step 2.2). Not yet wired into `AppServiceProvider`'s `AiProvider::class` binding (Step 2.7).
- 2026-10-14: Added `App\Actions\AI\DiscoverTrendsAction` (2h cache per client/platform set), `RecommendPostsAction` (optional `persist()` as a draft `idea` post), and `SuggestCaptionsForPostAction` (optional `apply()` onto a post's caption/hashtags), each constructor-injecting `AiProvider`; added Mockery-based unit tests and a container-resolved feature test for all three (Step 2.3). Controller/routes to call them are still Step 2.4's job.
- 2026-10-14: Added `config('ai.enabled')` (env `AI_ENABLED`), `App\Http\Controllers\AiController` with its three GET endpoints/routes, Owner/Strategist-only + org-scoped authorization, the editor's "Suggest with AI" panel, and the client dashboard's "Trends & Ideas" tab with platform toggles and pillar/campaign filters (Step 2.4). Added `tests/Feature/AiControllerTest.php` covering the authz matrix and the feature-flag gate.
- 2026-10-14: Added `App\Services\AI\AiGuardrailService` (post-generation banned-phrase filtering against `BrandBrain::voice['do_nots']`/`hashtag_policy['never_use']` + `config('ai.guardrails.prohibited_topics')`, PII/secret `scrub()`, and `platformWarnings()` for in-caption-link/hashtag-count/caption-length policy checks), wired into all three Step 2.3 Actions and `DeepSeekProvider`'s caption user prompt; added `Platform::maxHashtags()`/`allowsLinksInCaption()`, `config('ai.guardrails.prohibited_topics')`, the editor's per-suggestion policy-warning list, and `tests/Unit/Services/AI/AiGuardrailServiceTest.php` regression coverage (Step 2.6).

- 2026-10-14: Fixed all DeepSeek-backed requests (captions/ideas/trends) returning empty arrays: `AppServiceProvider` was always binding `AiProvider::class` to `NullAiProvider` regardless of configuration. `AiProvider::class` now resolves to `DeepSeekProvider` when `config('ai.enabled')` is true and `config('ai.deepseek.api_key')` is non-empty (core of Step 2.7's provider selection), falling back to `NullAiProvider` otherwise. Added `.env.example` DeepSeek entries, fixed `phpunit.xml` to force `AI_ENABLED=false`/empty `DEEPSEEK_API_KEY` for deterministic tests, and added `AiProviderTest` coverage for all four `ai.enabled` x `api_key` combinations. The per-org `organizations.ai_enabled` toggle and usage metrics remain open (rest of Step 2.7).
- 2026-10-14: Fixed two UI gaps reported against the Step 2.4 surfaces. (1) Trends & Ideas no longer disappear on refresh: added `ai_trends`/`ai_post_ideas` tables + `App\Models\AiTrend`/`AiPostIdea`, had `DiscoverTrendsAction`/`RecommendPostsAction` upsert their filtered results into them (keyed on `client_id`+`title`), exposed the persisted rows as `ClientController@show`'s `ai.trends`/`ai.ideas` props, reworked the "Trends & Ideas" tab into clickable list cards (`Link` to new `ai-trends.show`/`ai-post-ideas.show` routes/pages) instead of static inline blocks, and added a `persistIdea` endpoint so "Turn into draft post" works straight from an idea's detail page. (2) The editor's caption-suggestions list now auto-collapses to a one-line "applied ✓" summary (with a "Show suggestions" toggle) as soon as a suggestion is applied, instead of permanently taking up space below the caption field. Added `tests/Feature/AiControllerTest.php` coverage for the new detail/persist endpoints and `tests/Feature/AI/AiActionsTest.php` assertions for the new persisted rows.
- 2026-10-14: Fixed `App\Actions\AI\DiscoverTrendsAction::__invoke()` throwing a `TypeError` ("Return value must be of type `AiTrends`, `__PHP_Incomplete_Class` returned") when a cached value couldn't be unserialized back into the current `AiTrends` DTO shape. Replaced `Cache::remember()` with an explicit `Cache::get()`/`instanceof AiTrends` check/`Cache::put()`, discarding (`Cache::forget()`) and regenerating on any non-matching cached value instead of trusting it blindly, with a `warning` log on the discard path. Added `tests/Feature/AI/AiActionsTest.php::test_discover_trends_action_discards_a_stale_or_incompatible_cached_value` seeding an invalid cache entry and asserting the action self-heals.
- 2026-10-14: Reworked the "Trends & Ideas" tab's layout per UX feedback that the two side-by-side lists were far too long. Trends and ideas are now merged into one combined list (trends first, then the filtered ideas) instead of two `md:grid-cols-2` columns, each card tagged with a "Trend"/"Idea" `Badge` (plus the existing "Draft created" badge on ideas) so the type is still visible at a glance. The combined list is paginated client-side at 5 items per page with Previous/Next buttons and a "Page X of Y" label, resetting back to page 1 whenever the trend/filtered-idea counts change. A `Loader2`-spinner overlay now covers the list while a Refresh request is in flight, giving explicit visual feedback instead of only the Refresh button's own "Loading…" label.
- 2026-10-14: Added a "Filter by type" (All/Trends/Ideas) segmented toggle and a "Filter by channel" `Select` to the "Trends & Ideas" tab so the combined list can be narrowed down to just trends, just ideas, and/or a single platform, in addition to the existing pillar/campaign filters — both are client-side state (`aiTypeFilter`/`aiListPlatformFilter`) applied before pagination. Also fixed `AiController::persistIdea()` to redirect to the newly-created draft `Post`'s edit page (`posts.edit`) instead of back to the idea's own detail page, so "Turn into draft post" takes the user straight to the post it just created; updated `AiControllerTest` to assert the new redirect target.
- 2026-10-16: Trends and ideas are now limited to 3 per request (`DeepSeekProvider::IDEAS_PER_REQUEST`/`TRENDS_PER_REQUEST`): both prompts ask for exactly 3, and the parsed result is capped with `->take(3)` in case the model overshoots — keeps reasoning-model answers well inside `max_tokens` and cuts latency. Caption suggestions were already 3. Tests in `DeepSeekProviderTest`.
- 2026-10-16: Fixed "Ideas" returning nothing on `deepseek-v4-pro`: the reasoning model spent all of `max_tokens` (3000) on `reasoning_tokens` and returned empty content (`finish_reason = length`), and the retry re-sent the identical budget so it failed the same way. `DeepSeekProvider::requestStructured()` now doubles `max_tokens` (capped at `MAX_TOKENS_CEILING = 16000`) for the retry whenever the previous attempt ended with `finish_reason = length`, logging `max_tokens`/`max_tokens_raised_from` on the retry line; other failure kinds retry with the same budget. Reasoning models still need a higher `DEEPSEEK_MAX_TOKENS` baseline (≈8000) to avoid paying for the wasted first attempt. Regression tests in `DeepSeekProviderTest`.
- 2026-10-16: Reworked AI request logging so an empty result can be traced to its cause (see "Logging / Diagnostics"): `DeepSeekProvider` now logs a distinct, reasoned line for each failure path (HTTP exception/rejection with the vendor's error message, empty content, unparsable/truncated JSON, schema violation naming the exact key/item/field) with `finish_reason`, token usage, latency, and a scrubbed response excerpt; the three per-method validators were merged into one `shapeError()` that returns the reason instead of a bool. `AiGuardrailService` logs a received/kept/dropped summary per pass, and `NullAiProvider` logs that it answered (with why it was bound). Tests added in `DeepSeekProviderTest`, `AiGuardrailServiceTest`, `AiProviderTest`.
- 2026-10-16: Trends, ideas, and caption/hashtag suggestions are now explicitly grounded in the client's Brand Brain. Added `App\Support\AI\ClientContextBuilder` (client profile + Brand Brain + planning/active campaigns, PII-scrubbed) used by `DeepSeekProvider::buildSystemPrompt()` together with explicit grounding rules, plus method-specific grounding in each user prompt (post campaign for captions; pillar + pain point/offer per idea; brand-fit for trends). `DiscoverTrendsAction`'s cache key now includes the context fingerprint so brand brain edits bust the 2h trends cache. Tests: `ClientContextBuilderTest`, new `DeepSeekProviderTest` prompt assertions, `DiscoverTrendsActionTest` cache-bust case; `AiActionsTest` updated for the new key.
- 2026-10-16: Fixed "Turn into draft post" putting the AI's hashtags into the post caption: `RecommendPostsAction::persist()` now extracts inline `#tags` from the suggested caption into `Post::hashtags` (without `#`) and strips them from `master_caption`. Tests in `RecommendPostsActionTest`.
- 2026-10-16: Applying an AI caption suggestion in `posts/edit.tsx` now strips the leading `#` from each suggested hashtag (via the now-exported `normalizeTag()` from `components/hashtag-input.tsx`) before setting the hashtag field, so tags like `#demo` land as `demo`.
- 2026-10-17: Phase 16 AI Image Studio (Steps 16.1–16.14): `ImageProvider` contract + Gemini/OpenAI/Null adapters, `AiProvider::draftImageBrief()` (DeepSeek art director) with `AiGuardrailService::filterImageBrief()` and a 24h brief cache, generation sets/variants on the `ai-images` queue with idempotency, refine/regenerate/make final, attach to posts, Brand Brain visual references, quota + `ai_usage_logs` + Settings → AI usage, pruning, AI disclosure, and the studio UI on the idea page and post editor. `ClientContextBuilder::fingerprint()` now includes reference images. See "AI Image Studio (Phase 16)".
