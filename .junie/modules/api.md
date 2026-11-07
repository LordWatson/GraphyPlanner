# Public REST API & outgoing webhooks

## Purpose

Read access to an agency's data over HTTP, and outgoing webhooks so another system can be told the
moment something happens. Audit item 18 in `.junie/plans/market-readiness-audit.md`.

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

## Routes / Controllers

`routes/api.php`, all under `/api/v1`, all `api.token:<scope>`:

- `GET /me` → `Api\V1\MeController` — what this token is and may do.
- `GET /clients`, `GET /clients/{client}` → `Api\V1\ClientController`.
- `GET /posts`, `GET /posts/{post}` → `Api\V1\PostController` — channels inline; `updated_since`
  for incremental sync.
- `GET /social-accounts` → `Api\V1\SocialAccountController` — includes `connection_status`.
- `GET|POST /webhooks`, `GET|DELETE /webhooks/{endpoint}` → `Api\V1\WebhookEndpointController`.
- `GET /openapi.json` — the spec, unauthenticated (it is documentation, not data).

## Business Rules

- **One token concept.** The API uses `McpToken`, as that model's own docblock requires ("these two
  must become one token/org/scope concept, not two"). The class name now undersells what it does; a
  rename is mechanical churn that belongs in its own change.
- **The organization comes from the token, never from input.** There is no parameter a caller can
  send that widens what they see; `OrganizationScope` and `ClientAccessScope` do the rest.
- **Read-only in v1**, deliberately. Reading is where the demand is and cannot damage a customer's
  data while the shape is settling. Writes want idempotency keys designed in, not bolted on.
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
- `tests/Feature/Api/OutgoingWebhookTest.php` (12): subscribing over the API, secret shown once,
  scope gating, cross-org isolation, dispatch only to subscribers, signature, failure recording,
  auto-disable, no double delivery.
- `tests/Feature/Api/OpenApiSpecTest.php` (3): the spec is valid and served, **every v1 route is
  documented and nothing extra is**, and the documented events are the ones that exist.

## Known Gaps / Open Questions

- ~~`order.paid` is in the enum but nothing emits it.~~ **Wired 2026-11-07** in `MarkOrderPaidAction`,
  after the commit so nothing is announced that a rollback un-did.
- **Writes are not built.** v1 is read-only; `POST`/`PATCH` for posts and assets need an
  idempotency-key design first.
- **No Zapier/Make app.** It is an external submission built on this API, and review takes weeks —
  worth starting early, but it is not code in this repo.
- **`invoice.paid` is not an event.** Deliberately left out of `WebhookEvent` rather than listed
  and unwired: offering an event nothing emits is worse than not offering it. `order.paid` is in
  the enum but is **not yet emitted either** — wire it in `MarkOrderPaidAction` next.
- **No delivery replay endpoint or UI.** The rows are kept so it is possible; nothing exposes it.
- **No `McpToken` → `ApiToken` rename.** See the business rule above.
