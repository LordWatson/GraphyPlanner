# Public REST API & outgoing webhooks

## Purpose

Access to an agency's data over HTTP — reads, draft writes, and scheduling — plus outgoing
webhooks so another system can be told the moment something happens, see what we tried to send it,
and ask for a missed event again. Audit item 18 in `.junie/plans/market-readiness-audit.md`.

Three reasons it exists: API access is on every agency evaluation list, it is how customers glue
Alima to tools we will never build (their CRM, time tracker, warehouse), and a Zapier/Make listing
is a distribution channel with a long lead time. The MCP server is differentiated, but an AI
assistant protocol is not a substitute for `GET /api/v1/posts` when the buyer's ops person asks.

## Models / Tables

- `webhook_endpoints`: `org_id`, `url` (https only), `secret` (**encrypted** + `Hidden`), `events`
  (json), `disabled_at`, `disabled_reason`.
- `webhook_deliveries`: one attempt and its outcome — `event`, `payload`, `attempts`,
  `response_status`, `error`, `delivered_at`, `failed_at`. Kept rather than fired and forgotten,
  because the first question about a webhook is "did it arrive" and the second is "send it again".
  Both are now answerable over the API (`WebhookDeliveryController`).
- `api_idempotency_keys`: `org_id`, `key`, `endpoint`, `request_hash`, `response_status`,
  `response_body`, unique on `(org_id, key)`. One row per idempotent write, pruned after
  `IdempotentApiRequest::RETENTION_HOURS` (24h) by `api:prune-idempotency-keys`. Cascades with the
  organisation, and excluded from the data export as `TRANSIENT` — the stored response body is a
  copy of a post the archive already contains, so exporting it would hand the same content over
  twice, labelled as plumbing.

## Actions / Services / Contracts

- `App\Support\Api\BearerTokenAuth`: resolves a bearer token to an organization and acts as one of
  its members. **Shared with the MCP transport** — same tokens, same tenancy rule, so they cannot
  drift.
- `App\Support\Api\ApiResponse`: the one response envelope, and cursor pagination.
- `App\Services\Api\WebhookDispatcher`: the only way an event leaves Graphy. Records the delivery
  first, queues it second.
- `App\Jobs\DeliverWebhookJob`: signs and sends one delivery; retries with backoff; switches an
  endpoint off after `WebhookEndpoint::FAILURES_BEFORE_DISABLE` failures in a week.
- `App\Enums\WebhookEvent`: the closed list of subscribable events, and `forPostStatus()` which maps
  a post transition to one.
- `App\Http\Middleware\IdempotentApiRequest` (+ `App\Models\ApiIdempotencyKey`): makes a write
  safe to retry. Reserves the key **before** the work runs, so two concurrent retries collide on
  the unique index instead of both being let through — the ordering lesson of audit finding 2.
  Replays a completed key's stored response with `Idempotency-Replayed: true`; answers `409` for a
  key still in flight or reused for a different body; releases the reservation on a `4xx`/`5xx` so
  a corrected retry can run.
- The writes reuse the web editor's own Actions — `CreatePostAction`, `UpdatePostAction`,
  `EvaluatePostChecklistAction`, `TransitionPostStatusAction` — so there is no second
  implementation of the per-target local-time rules, carousel ordering, the activity log or the
  pre-publish gate. Same rule the MCP tools follow.

## Phase S — Stories with extras (assisted lane)

Plan: `.junie/plans/story-extras.md`, Steps S-5.4 and S-5.5. The lane is in `publishing.md` ›
"Stories with extras". An Instagram Story with a link or stickers is posted by a person, never by
Alima, and the API says so wherever a caller could assume otherwise.

- `App\Support\Api\StoryChannels`: the one place the API and the MCP tools accept, refuse and
  report Story extras, so they cannot drift from each other or from the editor.
    - `validate($input, $key, $rules)` adds the editor's own rules (`ValidatesStoryExtras`) for
      `format`, `story_extras` and `handoff_recipient` on each channel, and the platform check.
    - `withoutBlankExtras()`: to the editor a key that is present but blank means "added, not
      filled in yet". To a caller writing JSON, `"hashtag": null` means "no hashtag". So a null,
      an empty string, an empty list and an empty `link` or `poll` are dropped first.
    - `present(PostTarget)` → `story_extras`, `handoff_recipient`, `posted_by_hand`,
      `handoff_state`. `RELATIONS` is what it needs eager-loaded.
    - `note(Post)` → one plain sentence saying which channel a person posts by hand and when, or
      null when every channel publishes itself.
    - `carryOptions()` tells a create that it has to follow up through `UpdatePostAction`, the
      path `SyncTargetStoryExtrasAction` runs on.
    - `keepIfMissed()` is MCP only (see `mcp.md`). The API does accept `if_missed`.
- `ApiResponse::item($data, $meta = [])`: a second argument for things about the answer rather
  than the record. `meta.notes` is the list of sentences a caller should pass on to a person.
- `POST /posts` and `PATCH /posts/{post}` accept per-channel `format`, `story_extras` and
  `handoff_recipient`. `PATCH` now accepts `channels`, as a full replacement: a channel left out
  is removed; a listed one keeps `format`, `story_extras` and `handoff_recipient` unless they are
  sent, but its date and time are set from what is sent. `story_extras: null` or `{}` removes a
  Story's extras and it goes back to publishing itself.
- Every channel in a response carries `format`, `story_extras`, `handoff_recipient`,
  `posted_by_hand` and `handoff_state` (`needs_hand_posting` / `ready_to_post` /
  `posted_by_hand` / null).
- `POST /posts/{post}/schedule` now returns the full post plus `scheduled_at`, and `meta.notes`
  with the `StoryChannels::note()` sentence when a channel is posted by hand (an empty array
  otherwise).
- No response ever carries a handoff token or a `/story/` URL. There is no endpoint that opens or
  completes a handoff.
- Webhook `story.ready_to_post` (`WebhookEvent::StoryReadyToPost`), sent once, on the first
  reminder: `post_id`, `client_id`, `post_target_id`, `platform`, `handle`, `due_at`, `posted_by`
  and `url` (the signed-in `posts.story-handoff.open` route).
- `resources/api/openapi.json` documents all of the above.

## Routes / Controllers

`routes/api.php`, all under `/api/v1`, all `api.token:<scope>`:

- `GET /me` → `Api\V1\MeController` — what this token is and may do.
- `GET /clients`, `GET /clients/{client}` → `Api\V1\ClientController`.
- `GET /posts`, `GET /posts/{post}` → `Api\V1\PostController` — channels inline; `updated_since`
  for incremental sync.
- `POST /posts` (`write:drafts`, idempotent) → `Api\V1\PostController@store` — creates a **draft**
  with its channels and media. `PATCH /posts/{post}` (`write:drafts`, idempotent) edits one.
- `POST /posts/{post}/schedule` (`write:schedule`, idempotent) → `Api\V1\PostScheduleController` —
  its own controller and scope because it is the only write that reaches real audiences.
- `GET /social-accounts` → `Api\V1\SocialAccountController` — includes `connection_status`.
- `GET|POST /webhooks`, `GET|DELETE /webhooks/{endpoint}` → `Api\V1\WebhookEndpointController`.
- `GET /webhooks/{endpoint}/deliveries` (`read`),
  `POST /webhooks/{endpoint}/deliveries/{delivery}/replay` and `POST /webhooks/{endpoint}/enable`
  (both `write:drafts`) → `Api\V1\WebhookDeliveryController` — the dead-letter list, its replay,
  and the way back from an auto-disable.
- `GET /openapi.json` — the spec, unauthenticated (it is documentation, not data).

## Business Rules

- **One token concept.** The API uses `McpToken`, as that model's own docblock requires ("these two
  must become one token/org/scope concept, not two"). The class name now undersells what it does; a
  rename is mechanical churn that belongs in its own change.
- **The organization comes from the token, never from input.** There is no parameter a caller can
  send that widens what they see; `OrganizationScope` and `ClientAccessScope` do the rest.
- **A write stops at a draft.** `POST /posts` only ever creates a draft — `status` is not in the
  validated set, so it cannot be smuggled in — and `PATCH` refuses anything past draft with a
  `409`. That is narrower than `PostPolicy::update`, which lets the web editor change an approved
  post: a person can see the consequences of that, an integration cannot. Scheduling is the only
  way onward, it needs `write:schedule`, and it re-runs the full checklist server-side. The net
  effect is that no token can route around the approval step the product exists to enforce.
- **Every write accepts an `Idempotency-Key`, and none requires one.** A client whose request times
  out cannot tell whether the work happened, so the retry must be free; requiring the header would
  reject every first `curl` instead. Keys are scoped per organization, so two tenants using the
  same string do not collide.
- **A replay is a replay, not a new event.** The payload goes out byte for byte, `occurred_at`
  included — the receiver is being told about something that happened then, not now.
- **A disabled endpoint can be switched back on.** The auto-disable used to be a one-way door: the
  only recovery was a new endpoint, which mints a new secret and means reconfiguring the receiver,
  which made the kept deliveries useless. `POST /webhooks/{endpoint}/enable` is the way back, and
  replay refuses while an endpoint is off rather than burning the attempt on a job that would fail
  immediately.
- **Cursor pagination, no totals.** Offset paging silently skips and repeats rows when the set
  shifts under a client walking it, and the client cannot tell.
- **Validation at the boundary.** A malformed filter is a 422 naming it, never a 500. An
  over-large `per_page` is refused rather than silently capped — a caller who thinks they asked for
  10,000 and quietly got 200 will write a sync that misses rows.
- **Rate limited per token**, not per IP: integrations behind one office address must not throttle
  each other. 300/min per token, 30/min per IP unauthenticated.
- **Deliveries are signed the way we verify inbound webhooks**:
  `X-Alima-Signature: sha256=<hex>` over `HMAC_SHA256(secret, "{X-Alima-Timestamp}.{raw body}")`.
  Audit finding 4 is why it is defined precisely rather than improvised.
- **The signing secret is shown once**, at creation. Anyone holding it can forge a delivery the
  receiver will trust, so it never reaches a response, a page or a log line.
- **A dead endpoint is switched off, with a reason.** A URL that stopped existing months ago would
  otherwise keep a worker retrying every event forever and tell nobody.

## Tests

- `tests/Feature/Api/ApiV1Test.php` (15): auth, scopes, tenancy in both directions, response
  shapes, filters, cursor pagination, 422 on bad input, no credential leakage.
- `tests/Feature/Api/ApiV1WritesTest.php` (19): a draft is all a create can make (including that a
  smuggled `status` is ignored), another client's campaign cannot be borrowed, only the fields sent
  are changed, an approved post is a `409` and a scheduled one a `403` from the policy, scope
  gating for drafting vs scheduling, a failing checklist names what is outstanding, and the
  idempotency rules — replay, key reuse, a failed write not burning its key, per-org scoping, and
  the retention prune.
- `tests/Feature/Api/WebhookDeliveryReplayTest.php` (12): the list newest-first with its payload
  and error, status filtering (and a `422` for an unknown filter rather than a full list), cross-org
  isolation, replay of a failed delivery with `attempts` kept and `occurred_at` unchanged, refusal
  for a pending delivery or a disabled endpoint, scope gating, and the enable-then-replay recovery.
- `tests/Feature/Api/OutgoingWebhookTest.php` (12): subscribing over the API, secret shown once,
  scope gating, cross-org isolation, dispatch only to subscribers, signature, failure recording,
  auto-disable, no double delivery.
- `tests/Feature/Api/OpenApiSpecTest.php` (3): the spec is valid and served, **every v1 route is
  documented and nothing extra is**, and the documented events are the ones that exist.
- `tests/Feature/Api/ApiV1StoryExtrasTest.php` (14, Phase S): a post returns each channel's
  extras and lane; a due Story reads `ready_to_post` without its handoff link; creating and
  editing channels with extras; an integration can choose `publish_plain`; an empty object clears
  extras; extras on a non-Story channel are stored but it still publishes itself; the kill switch
  off ignores extras; the editor's validation rules apply; a null key means "not added"; another
  organization's token cannot read or write; scheduling says which channel is posted by hand and
  has no note otherwise.
- `tests/Feature/Stories/StoryHandoffNotificationTest.php`:
  `test_the_ready_to_post_webhook_fires_on_the_first_notification_only_and_carries_no_token`.

## Known Gaps / Open Questions

- **Phase S (2026-11-13): no endpoint confirms a Story as posted, and none lists handoffs.** That
  is deliberate for now: "posted" is the poster's word, given on the handoff page or in the
  editor. `story.ready_to_post` is the only Story event; there is no `story.posted` or
  `story.missed`. A miss arrives as `post.failed` for a Story-only post; on a mixed post the
  status is `partially_published`, which `WebhookEvent::forPostStatus()` maps to `post.published`.

- ~~`order.paid` is in the enum but nothing emits it.~~ **Wired 2026-11-07** in `MarkOrderPaidAction`,
  after the commit so nothing is announced that a rollback un-did.
- ~~**Writes are not built.**~~ **Built 2026-11-07** for posts (create, edit, schedule) with
  idempotency keys. **Asset upload over the API is still missing** — a multipart endpoint is a
  different shape from these JSON writes (size limits, virus scanning, the storage adapter), and
  `RequestMediaUploadTool` already solves it for MCP with a signed one-shot URL, which is probably
  the right answer here too.
- **No analytics-summary or invoice endpoints.** Both are named in item 18 step 2 and neither is
  built; `GetAnalyticsSummaryTool` shows the shape the first one should take.
- **No Zapier/Make app.** It is an external submission built on this API, and review takes weeks —
  worth starting early, but it is not code in this repo.
- **`invoice.paid` is not an event.** Deliberately left out of `WebhookEvent` rather than listed
  and unwired: offering an event nothing emits is worse than not offering it. `order.paid` is in
  the enum but is **not yet emitted either** — wire it in `MarkOrderPaidAction` next.
- ~~**No delivery replay endpoint or UI.**~~ **Endpoint built 2026-11-07.** There is still **no
  UI** for it: an agency owner who is not writing code against the API cannot see the dead-letter
  list, which is the same shape of gap as finding 4 step 3's missing webhook-secret banner.
- **No `McpToken` → `ApiToken` rename.** See the business rule above.
- **"API access" is not in the plan matrix.** Item 18 step 6 asks for it in `config/plans.php`;
  gating the API by plan is a pricing decision that belongs with item 22, which is itself waiting
  on the tax question (GTM-0.3), so it is deliberately not invented here.

## Changelog

- 2026-11-13: Phase S — Stories with extras (assisted lane). Channels accept and return `format`,
  `story_extras` and `handoff_recipient`, and report `posted_by_hand` / `handoff_state`; the
  `PATCH` on a post accepts `channels`; scheduling returns the full post and `meta.notes`; new webhook
  `story.ready_to_post`. Shared with MCP through `App\Support\Api\StoryChannels`.
