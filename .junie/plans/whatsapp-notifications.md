# Graphy — WhatsApp Notifications Plan

_Written 2026-10-24, against the code as it stands after the notifications work (Step M-6.7 / I-12.1):
database notifications, the `NotificationPreferences` matrix, the daily digest, and the `ChatNotifier`
seam (`NullChatNotifier`)._

Today Graphy reaches people three ways: the in-app notification **bell** (database notifications,
every role), **email** (review requests, invoices, the daily digest, scheduled reports), and, for
business/creator workspaces, **web push** (`push:send-post-reminders`). The agency's most important
moments — _"a post is waiting for your client to approve it"_ and _"this goes out in an hour"_ —
still depend on someone opening an email or the app.

This plan adds **WhatsApp** as a fourth delivery channel. It is the message clients actually read:
a post needing approval pings the client's phone, they tap through to the review link, and the post
moves. It also keeps the team honest — a reminder before a scheduled post, an alert when a publish
fails. It is **opt-in per person, opt-out any time**, and it reuses the notification classes and the
preference matrix that already exist rather than inventing a parallel system.

This file sits alongside `.junie/plans/graphy-development-steps.md` (what to build),
`.junie/plans/improvements.md`, `.junie/plans/campaigns-enhancement.md` and
`.junie/plans/business-workspaces.md`. How to use it (same rules as those plans):

- Each step is sized to hand to Junie as one unit of work: a goal, the files/models it touches, and
  a **Done when**.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), one-job Actions in `App\Actions\WhatsApp\*` / `App\Actions\Notifications\*`, reusable
  logic in `App\Services\*`, a swappable integration behind an interface in `App\Contracts\*`,
  `DB::transaction()` around multi-write logic, logging at boundaries with ids (**never** a token,
  phone number in full, or message body as a secret), and PHPUnit coverage (class-based, extending
  `Tests\TestCase`: happy path, role rejections, edge cases, a regression test for any bug).
- **Every step updates the module docs** in the same change — primarily `.junie/modules/notifications.md`,
  plus any other doc it touches (`settings.md`, `clients.md`, `organizations-users.md`, `posts.md`,
  `client-portal.md`, `ops.md`). Use the `sync-module-docs` skill.
- PHPStan level 7 passes with no new ignores or baseline entries. SQL works on MySQL 8 **and** the
  in-memory SQLite test database. Frontend steps follow `.junie/brand-guidelines.md` and use the
  `graphy-frontend-page` skill (house components, semantic tokens, Wayfinder routes, `can`-gated UI).
- Any new scheduled command is added to `App\Support\Scheduling\SchedulerHeartbeat::COMMANDS` and the
  governance registries in the same change, or the full suite fails.
- Step ids are prefixed `W-` (e.g. `Step W-2.1`) so they never collide with `Step 2.1`, `I-2.1`,
  `B-2.1` or `C-2.1`. Mark finished steps `✅ Done` with an "Implemented via …" note.
- Priority tags: **[MVP]** needed before WhatsApp can be switched on for a real org · **[V1]** makes
  it a clear reason to pick Alima · **[Stretch]**.

---

## 1. Product framing

### 1.1 The pitch

> The approval that used to sit in an inbox now buzzes in their pocket. Tap, approve, done.

Clients don't live in your app. They live in WhatsApp. When a post needs their sign-off, a short,
branded WhatsApp message with a one-tap review link beats a fourth "just following up" email. For the
team, WhatsApp is the nudge that stops a scheduled post slipping and the alarm when a publish breaks.

### 1.2 What we send (the notification set)

| Event | Who gets it | Default | Notes |
|---|---|---|---|
| Post waiting on client approval | Client contacts / ClientReviewer | **opt-in** | The headline feature. Links to the review link / portal. |
| Post scheduled — reminder before it goes out | The owning team member (assignee) | opt-in | Reuses the existing upcoming-reminder sweep. |
| Client approved / requested changes | Studio (Owner/Strategist) | opt-in | So the team acts the moment a client decides. |
| Publish failed | Studio | opt-in | Urgent; the one most teams will keep on. |
| Approval overdue (SLA breached) | Studio + client | opt-in | Layers onto the existing `ApprovalOverdue` flow. |
| Invoice issued / payment reported | Client billing contacts / studio | opt-in | Reuses `InvoiceIssued`. |
| Campaign brief ready for the client | Client contacts | opt-in | Reuses `CampaignBriefReady`. |
| Daily digest (one summary message) | Any member | opt-in | Fulfils the existing `ChatNotifier` seam. |

Every one of these already has a `App\Notifications\*` class. This plan **does not write new
notification events** — it adds a WhatsApp delivery channel and lets each existing notification opt
into it, exactly as the bell and digest already do.

### 1.3 Principles

1. **One notification, many channels.** A notification declares its category; the person's
   preferences decide which channels fire (`in_app`, `digest`, and now `whatsapp`). We extend
   `NotificationPreferences`, never fork the notification classes.
2. **Opt-in, always reversible.** WhatsApp defaults **off** for every category and every person.
   A number must be captured and verified before anything sends, and an inbound "STOP" turns it off
   instantly. This is both courtesy and a hard Meta/WhatsApp Business policy requirement.
3. **Best-effort, never blocking.** Delivery runs on a queue, after commit. A WhatsApp failure never
   breaks the request, the publish, or the in-app bell — the bell/email remain the source of truth.
4. **Swappable provider behind a contract.** The WhatsApp Business Cloud API (Meta) is the first
   implementation, bound behind `App\Contracts\WhatsAppClient` with a `NullWhatsAppClient` default —
   the same shape as `PublishAdapter`/`UploadPostAdapter`. A BSP (Twilio, 360dialog) can drop in later.
5. **Templates, not free text.** Business-initiated messages outside the 24-hour customer-service
   window must use Meta-approved message templates. Every outbound notification maps to a named
   template; we never send ad-hoc marketing text.
6. **No secrets in logs.** Access tokens, the app secret and webhook signatures are never logged;
   phone numbers are logged only as a masked last-4; message bodies are not logged as payloads.

### 1.4 Who can send and receive (role scoping)

| Capability | Owner | Strategist | Designer | Viewer | ClientReviewer |
|---|---|---|---|---|---|
| Turn WhatsApp on for the org, set credentials | ✅ | — | — | — | — |
| Opt **themselves** in/out, verify their number | ✅ | ✅ | ✅ | ✅ | ✅ |
| Set a client contact's WhatsApp consent | ✅ | ✅ | — | — | — |
| Receive client-facing WhatsApp (approvals, briefs) | — | — | — | — | ✅ (own client) |
| View the org's WhatsApp message log | ✅ | ✅ | — | — | — |

Org-level enablement follows the existing settings/credentials rule (Owner, like `upload_post_key`).
Per-person opt-in is available to **every** role, including client-portal reviewers — "all opt in /
opt out available to each member", as requested.

### 1.5 Out of scope

- **Inbound conversations / a WhatsApp inbox.** We handle inbound only for consent control (STOP/
  START) and delivery receipts. Two-way client chat is the separate Inbox module (Phase 3 / M-9).
- **Approving a post by replying "yes" in WhatsApp.** The link takes them to the review screen; we do
  not parse approval decisions out of chat (auditability, and it needs the Inbox module).
- **SMS / Telegram / Slack.** WhatsApp only. The `ChatNotifier` seam stays "deliberately not Slack".
- **Per-message billing/pricing analytics.** Meta charges per conversation; surfacing that cost is a
  Stretch reporting item (W-4.4), not core.

### 1.6 Dependencies and seams we reuse

| Needed | Existing building block | How |
|---|---|---|
| The channel fires per notification | `App\Notifications\*` + `via()` | Each class's `via()` adds `'whatsapp'` when the recipient wants it. |
| Per-person on/off | `NotificationPreferences` (`in_app`/`digest`) | Add a third channel, `whatsapp`, default off (W-0.5). |
| Category muting already filters | `PostNotificationService::send` | Already checks `wantsInApp`; the channel check sits in `via()` so it covers every send site. |
| Client phone numbers | `clients.client_contacts.phone` (exists) | Add consent + verification columns (W-1.2). |
| Scheduled-post reminder sweep | `posts:send-upcoming-reminders` / `SendUpcomingPostReminderEmailsAction` | Reuse the sweep; add a WhatsApp send alongside the email (W-2.2). |
| Org digest seam | `App\Contracts\ChatNotifier` + `NullChatNotifier` | The WhatsApp digest fulfils this seam (W-2.6). |
| Encrypted org credentials | `organizations.upload_post_key` pattern + `PublishCredentials` | Store `whatsapp_*` the same way, read via a resolver, never logged (W-0.1, W-4.1). |
| Queue + after-commit + Horizon | `App\Support\Queues`, `viaQueues()` | Add a `whatsapp` queue; notifications already `ShouldQueue` with `after_commit`. |

None of these block the MVP. Where a dependency hasn't landed, the step ships its own part and leaves
a clearly named seam.

---

## Phase W-0 — Delivery foundations

Goal: a message can reach a real WhatsApp number through a swappable, testable seam — with nothing
yet wired to a notification.

### Step W-0.1 — WhatsApp client contract + null implementation + config [MVP]
- `App\Contracts\WhatsAppClient`: the swappable provider seam, mirroring `PublishAdapter`.
  - `sendTemplate(Organization $org, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult`
  - `sendSessionText(Organization $org, string $toPhone, string $body): WhatsAppSendResult` (only
    valid inside the 24h window; used for replies, not business-initiated sends)
  - `verifyCredentials(Organization $org): bool` ("Test connection", never sends)
- Value objects in `App\Support\WhatsApp\`: `WhatsAppTemplateMessage` (template name, locale,
  ordered component variables), `WhatsAppSendResult` (ok, provider message id, error code/reason —
  no raw payload).
- `App\Services\WhatsApp\NullWhatsAppClient`: the bound default — records nothing is sent, returns a
  not-configured result, `verifyCredentials() === false`. Bound in `AppServiceProvider` (replace the
  default binding only once W-0.2 lands and the env is set).
- `config/services.php` gains a `whatsapp` block: `base_url`
  (`https://graph.facebook.com`), `graph_version`, `app_secret` (for webhook signature),
  `verify_token` (webhook handshake). Per-org `phone_number_id`, `business_account_id` and access
  token live on the organization (W-4.1), resolved through a small `WhatsAppCredentials` resolver,
  **never** read from env directly in a caller.
- **Done when**: the contract, value objects and `NullWhatsAppClient` exist; a unit test asserts the
  null client never "sends" and reports not-configured; PHPStan level 7 is clean. See
  `.junie/modules/notifications.md` and `settings.md`.

### Step W-0.2 — Cloud API client implementation [MVP]
- `App\Services\WhatsApp\CloudApiWhatsAppClient implements WhatsAppClient`: talks to the Meta WhatsApp
  Business Cloud API (`POST /{phone_number_id}/messages`) via Laravel's `Http` client with a timeout
  and a single retry on 5xx/429.
  - `sendTemplate` builds the `template` message payload (name, language, `components`), handles the
    common error codes (131026 undeliverable, 131047 re-engagement/24h window, 132xxx template
    issues, 100 invalid param) into a typed `WhatsAppSendResult`.
  - Logs each call at the boundary: `info` on accept (org id, notification class, provider message id,
    masked phone), `warning`/`error` on failure (error code + reason). Never logs the token or body.
- Bind `WhatsAppClient` → `CloudApiWhatsAppClient` in `AppServiceProvider` only when the org/env is
  configured; otherwise keep `NullWhatsAppClient` (so tests and un-configured orgs are safe).
- Phone numbers normalised to E.164 before sending (`App\Support\WhatsApp\PhoneNumber`).
- **Done when**: tests with `Http::fake()` cover a successful template send, a 24h-window rejection,
  an undeliverable number, and a 429 retried once; no test performs a real network call; the token
  never appears in any logged context (assert on the fake log). See `.junie/modules/notifications.md`.

### Step W-0.3 — The `whatsapp` notification channel [MVP]
- `App\Notifications\Channels\WhatsAppChannel`: a custom Laravel notification channel. On `send`, it
  (1) resolves the recipient's WhatsApp route (W-0.4), (2) calls
  `$notification->toWhatsApp($notifiable)` → a `WhatsAppTemplateMessage`, (3) resolves the recipient's
  organization, (4) calls `WhatsAppClient::sendTemplate`, (5) records a `WhatsAppMessage` log row
  (W-3.1). No-ops (and logs `info`) when there is no route, no consent, or no configured org.
- A `Notifications\Concerns\SendsWhatsApp` trait (or a base method) giving a default
  `toWhatsApp()` that derives a template message from the notification's existing `title`/`body`/`url`
  so classes that don't override it still work.
- Register the channel in `AppServiceProvider` (`Notification::extend('whatsapp', …)`).
- `App\Support\Queues` gains `WHATSAPP = 'whatsapp'`; notifications route `whatsapp` → that queue via
  `viaQueues()`. Add the queue to Horizon config and `Queues::all()` (ops.md), and to the queue-infra
  governance test.
- **Done when**: a fake-notifiable test sends through the channel to a `WhatsAppClient` spy and
  asserts the template message and a logged row; a recipient with no route is a silent no-op. See
  `.junie/modules/notifications.md` and `ops.md`.

### Step W-0.4 — Recipient routing (members + client contacts) [MVP]
- Members: migration adds `users.phone` (nullable, E.164) and `users.phone_verified_at` (nullable).
  `User::routeNotificationForWhatsApp()` returns the verified phone (null otherwise). Update
  `UserFactory` and profile settings validation.
- Client-facing: `ClientContact` already has `phone`. Add `ClientContact::routeNotificationForWhatsApp()`
  gated on its consent flag (W-1.2). For the unauthenticated review-link audience, support an
  on-demand route (`Notification::route('whatsapp', $phone)`) so a client without a portal user can
  still be messaged where consent exists.
- A `WhatsAppRoutable` interface or shared resolver so the channel treats User, ClientContact and
  on-demand routes uniformly.
- **Done when**: a user with an unverified number has a null route; a verified one routes; a client
  contact routes only with consent; tenant isolation holds (a route never crosses org/client). Tests
  in `tests/Feature/Notifications/`. See `.junie/modules/organizations-users.md` and `clients.md`.

### Step W-0.5 — WhatsApp as a third preference channel [MVP]
- Extend `App\Support\Notifications\NotificationPreferences`:
  - `CHANNELS = ['in_app', 'digest', 'whatsapp']`.
  - `defaultsFor()` — `whatsapp => false` for **every** category (opt-in). `Other`'s in-app lock is
    unchanged; `whatsapp` is never force-on.
  - `wants($user, $category, 'whatsapp')`, `matrix()` (adds a `whatsapp` + `whatsapp_locked:false`
    column), and `sanitize()` (accepts the new channel, defaulting off).
  - A `wantsWhatsApp(User, string $notificationClass)` convenience mirroring `wantsInApp`.
- Each existing notification's `via()` adds `'whatsapp'` when the notifiable `wantsWhatsApp(...)`
  **and** has a WhatsApp route — a small shared helper so every class does it the same way. Start by
  wiring only the classes W-2 covers; the rest inherit the capability but stay off by default.
- **Done when**: unit tests cover the new defaults (all whatsapp off), round-tripping a payload
  through `sanitize`, and that `via()` adds `whatsapp` only when both preference and route exist.
  See `.junie/modules/notifications.md`.

---

## Phase W-1 — Consent, verification and templates

Goal: nobody is messaged without an explicit, verified opt-in, and every send has an approved
template behind it. This is the compliance spine — build it before any real send in W-2.

### Step W-1.1 — Member opt-in and number verification [MVP]
- On `settings/notifications` (the existing `NotificationPreferenceController` / `notifications.tsx`):
  add a WhatsApp column to the category matrix **and** a "Your WhatsApp number" panel: enter a number,
  receive a one-time code (sent via the configured WhatsApp template `wa_verify_code`), confirm it →
  sets `users.phone_verified_at`. Changing the number clears verification.
- `VerifyWhatsAppNumberAction` + `ConfirmWhatsAppNumberAction` (throttled; code hashed at rest with an
  expiry, like `ReviewToken`). The WhatsApp toggles are disabled in the UI until a number is verified.
- **Done when**: tests cover request/confirm, a wrong/expired code rejected, re-entering a number
  clearing verification, and that WhatsApp preferences can't be enabled without a verified number.
  See `.junie/modules/notifications.md` and `auth.md`/`settings.md`.

### Step W-1.2 — Client contact consent [MVP]
- Migration adds to `client_contacts`: `receives_whatsapp` (bool, default false),
  `whatsapp_consent_at` (nullable), `whatsapp_consent_source` (nullable string: 'portal', 'staff',
  'import'). A `scopeReceivingWhatsApp()` mirroring `scopeReceivingReviewEmails`.
- Staff can set consent from the client contacts UI (Owner/Strategist), recording source 'staff' and
  the acting user. Clients can opt in themselves from the portal (W-1 portal toggle), source 'portal'.
- `StoreClientContactRequest`/`UpdateClientContactRequest` validate the phone as a sendable number
  when consent is on.
- **Done when**: a contact without consent is never routed; setting consent records source + timestamp;
  tenant isolation holds; tests in `tests/Feature/Clients/`. See `.junie/modules/clients.md` and
  `client-portal.md`.

### Step W-1.3 — Inbound STOP/START + suppression list [MVP]
- `whatsapp_suppressions` (`org_id` nullable for global, `phone`, `reason`, `created_at`): a number
  here is never messaged, whatever the preference says. The channel (W-0.3) checks it before sending.
- The inbound webhook (W-3.1) recognises "STOP"/"UNSUBSCRIBE" (adds a suppression + clears the
  matching user/contact consent) and "START"/"UNSTOP" (removes it). Logged, never auto-replied beyond
  Meta's own handling.
- A hard-bounce/undeliverable result from a send (W-0.2) also adds a suppression so we stop retrying a
  dead number.
- **Done when**: a STOP suppresses and a START releases (tests), a suppressed number short-circuits
  every send path, and the suppression check is covered at the channel boundary. See
  `.junie/modules/notifications.md`.

### Step W-1.4 — Message template registry [MVP]
- `App\Support\WhatsApp\MessageTemplate` enum (or registry): each outbound notification → a Meta
  template name + locale + the ordered variables it fills. E.g. `post_waiting_client` →
  `{client_name}`, `{post_summary}`, `{review_url}`. Documented so the exact template text submitted
  to Meta for approval lives next to the code that fills it.
- A seed/fixture list of the templates an org must get approved (names + sample bodies) in
  `notifications.md`, plus a `whatsapp:templates` artisan command that prints them for submission.
- A guard: sending a template whose name isn't in the registry is a programming error (exception in
  tests), so a notification can't send an unapproved template.
- **Done when**: the registry maps every W-2 notification to a template with the right arity; a unit
  test asserts each notification's `toWhatsApp()` fills exactly the template's variables. See
  `.junie/modules/notifications.md`.

---

## Phase W-2 — The notifications

Goal: the real messages go out — opt-in, templated, queued, logged. Each step is "wire an existing
notification class to the channel", not "invent an event".

### Step W-2.1 — Post waiting on client approval → client [MVP]
- `PostWaitingForClientReview` (and the review-link audience) gains `toWhatsApp()` using the
  `post_waiting_client` template with a tappable review URL (the portal link for a ClientReviewer, the
  signed `/review/:token` link for a non-portal contact).
- Fires only for client contacts / reviewers with consent + the `approvals` WhatsApp preference on.
  Sent after commit, alongside the existing bell notification and `ClientReviewRequestedMail`.
- **Done when**: a consenting client gets exactly one WhatsApp with a working review link; a
  non-consenting client gets none; the message never leaks internal fields; tenant isolation holds.
  Tests in `tests/Feature/Notifications/`. See `.junie/modules/notifications.md` and `posts.md`.

### Step W-2.2 — Scheduled-post reminder → the responsible member [MVP]
- Reuse the existing `posts:send-upcoming-reminders` sweep (`SendUpcomingPostReminderEmailsAction`):
  for each upcoming post, also send a WhatsApp (`post_scheduled_reminder` template) to the post's
  assignee/owner **if** they've opted into `publishing` (or a dedicated reminder preference) on
  WhatsApp and verified a number. No new scheduled command needed if we extend the existing sweep;
  if a separate cadence is wanted, add `whatsapp:send-schedule-reminders` to
  `SchedulerHeartbeat::COMMANDS` + governance.
- Idempotent per post+window (reuse the sweep's existing "already reminded" guard) so a re-run never
  double-sends.
- **Done when**: a member who opted in gets one reminder per post window; opted-out members get none;
  the sweep stays idempotent (test re-runs the command and asserts no second send). See
  `.junie/modules/notifications.md` and `posts.md`.

### Step W-2.3 — Client decision → studio [V1]
- `PostApprovalDecided` gains `toWhatsApp()` (`post_decided` template: approved vs changes-requested),
  sent to Owner/Strategist who opted into `approvals`. Deep-links to the post editor.
- **Done when**: both decision variants render the right template; only opted-in studio members are
  messaged; tests cover each branch. See `.junie/modules/notifications.md`.

### Step W-2.4 — Publish failed → studio [V1]
- `PostPublishFailed` gains `toWhatsApp()` (`post_publish_failed` template), to opted-in studio members
  on the `publishing` category. The "keep this one on" alarm.
- **Done when**: a failed publish messages opted-in studio members once with the failing target(s)
  summarised; tests cover full-fail and partial-fail wording. See `.junie/modules/notifications.md`.

### Step W-2.5 — Overdue approvals, invoices, campaign briefs [V1]
- Wire `ApprovalOverdue`, `InvoiceIssued` and `CampaignBriefReady` to `toWhatsApp()` with their
  templates, each gated on its category's WhatsApp preference and the recipient's consent. These reuse
  the existing dispatch sites (`SendOverdueApprovalRemindersJob`, `InvoiceDeliveryService`, campaign
  actions) with no new events.
- **Done when**: each sends only to opted-in, consenting recipients; the invoice/brief client messages
  never leak internal or billing-restricted fields; tests per notification. See
  `.junie/modules/notifications.md`, `billing.md`, `campaigns.md`.

### Step W-2.6 — WhatsApp daily digest (fulfil the `ChatNotifier` seam) [Stretch]
- Implement `App\Services\Notifications\WhatsAppChatNotifier implements ChatNotifier` so
  `SendNotificationDigestAction`'s existing seam sends a single `daily_digest` template per opted-in
  member (count of updates + a link to the bell), replacing the `NullChatNotifier` binding when
  WhatsApp is configured. `isConfiguredFor()` reflects the org's WhatsApp setup.
- **Done when**: the digest action sends one WhatsApp summary to opted-in members and none to others;
  the null binding still applies when WhatsApp is off; tests cover both. See
  `.junie/modules/notifications.md`.

---

## Phase W-3 — Delivery tracking and reliability

Goal: we know whether a message landed, we stop sending to dead/opted-out numbers, and a WhatsApp
outage never degrades the rest of the app.

### Step W-3.1 — Message log + delivery webhook [MVP]
- `whatsapp_messages` (`org_id`, nullable `user_id`/`client_contact_id`, `to_phone` (masked in any
  surfaced view), `notification_type`, `template`, `provider_message_id`, `status`
  (queued/sent/delivered/read/failed), `error_code`, `error_reason`, timestamps). Written by the
  channel (W-0.3) and updated by the webhook.
- `POST /webhooks/whatsapp` (+ the `GET` verify-token handshake): verifies Meta's `X-Hub-Signature-256`
  against `app_secret`, then updates the matching `whatsapp_messages` row from status callbacks and
  routes STOP/START to W-1.3. Thin controller → `HandleWhatsAppWebhookAction`. Runs on the `webhooks`
  queue. Unsigned/replayed requests rejected.
- **Done when**: a signed delivery callback updates the row; an unsigned one is rejected; an inbound
  STOP suppresses; tests use faked payloads with correct/incorrect signatures. See
  `.junie/modules/notifications.md` and `ops.md`.

### Step W-3.2 — Window handling, retries and email fallback [V1]
- Business-initiated sends always use templates (W-1.4), so the 24h window rarely blocks; when the API
  still returns a re-engagement error, the channel logs it and (for the client-approval message)
  **falls back** to ensuring the email went — never silently drops the notification.
- Per-org send rate limiting (respecting Meta's messaging limits) via a `RateLimiter`; transient
  failures retried by the queue with backoff; permanent failures (invalid number) suppress (W-1.3).
- **Done when**: a windowed rejection records `failed` + leaves the email path intact; a transient
  error retries; a permanent error suppresses and doesn't retry; tests cover each. See
  `.junie/modules/notifications.md`.

### Step W-3.3 — Observability [V1]
- Structured logs at every boundary (send accepted/failed, webhook received, suppression changed) with
  org id, notification class, provider message id, masked phone — never token/body. A small
  `whatsapp` counter set (sent/delivered/failed) exposed the same way other ops metrics are.
- **Done when**: the ops doc lists the log events and metrics; a test asserts no token/full-number
  appears in logged context. See `.junie/modules/ops.md`.

---

## Phase W-4 — Settings, admin and ship

Goal: an Owner can turn it on, see it working, and trust it; the feature is demoable and gated.

### Step W-4.1 — Org WhatsApp settings [MVP]
- Settings page (Owner-only) to enable WhatsApp and store `whatsapp_phone_number_id`,
  `whatsapp_business_account_id` and the access token — encrypted at rest and resolved via
  `WhatsAppCredentials`, exactly like `upload_post_key` (never logged, never sent to the frontend).
  A "Test connection" button calls `WhatsAppClient::verifyCredentials`.
- Enabling WhatsApp for the org is what flips the binding from `NullWhatsAppClient` to the Cloud API
  client for that org's sends.
- **Done when**: credentials round-trip encrypted; "Test connection" reflects validity; a non-Owner
  gets 403; the token never reaches an Inertia prop (isolation test). See `.junie/modules/settings.md`.

### Step W-4.2 — Message log UI [V1]
- An Owner/Strategist view of `whatsapp_messages` (recent sends, status, template, masked recipient,
  failure reason), filterable by status and notification type — for debugging "did my client get it?".
- **Done when**: the list is org-scoped (tenant test), numbers are masked, and a Designer/Viewer can't
  reach it. See `.junie/modules/notifications.md`.

### Step W-4.3 — Demo/preview + entitlement seam [V1]
- Seed demo `whatsapp_messages` and a configured demo org so `/preview` shows the settings page and a
  populated log, staying read-only. Gate WhatsApp behind a `CampaignFeatures`-style
  `WhatsAppFeatures`/org-setting check (one class, no scattered `if`s), ready for plan entitlements
  (I-11.3 / B-9).
- **Done when**: preview shows the feature without sending; the gate returns 403 + hides UI via `can`
  when off; tests cover the gate. See `.junie/modules/notifications.md` and `preview-mode.md`.

### Step W-4.4 — Hardening, docs and exit [MVP]
- Full `composer ci:check` green; a tenant-isolation test for every new route/table; the
  queue/scheduler governance registries updated; `notifications.md` reflects every shipped step; a
  short runbook (how to get templates approved, how to onboard an org's WhatsApp number).
- **Done when**: the exit criteria below all hold. See `.junie/modules/notifications.md`.

---

## Suggested order and MVP cut

**MVP (switch WhatsApp on for one real org, one headline notification):**
W-0.1 → W-0.5, W-1.1, W-1.2, W-1.3, W-1.4, W-2.1, W-2.2, W-3.1, W-4.1, W-4.4.

That gives: a swappable Cloud API client, a `whatsapp` preference channel defaulting off, member and
client consent with number verification, STOP handling, the approved-template registry, the
post-waiting-on-client and scheduled-reminder messages, a delivery webhook + message log, Owner
settings, and a hardened finish. Enough to demo "your client approves from their phone" end to end.

**V1 (a clear reason to pick Alima):** W-2.3, W-2.4, W-2.5, W-3.2, W-3.3, W-4.2, W-4.3.

**Stretch:** W-2.6 (WhatsApp digest).

## Exit criteria

- No one is messaged on WhatsApp without an explicit, verified opt-in; an inbound STOP stops all
  messages immediately and is irreversible without a START.
- Every business-initiated message uses a registered, Meta-approved template; no ad-hoc text is sent
  outside the 24h window.
- The WhatsApp provider sits entirely behind `App\Contracts\WhatsAppClient`; swapping to
  `NullWhatsAppClient` (the default) makes every notification path a safe no-op with the bell/email
  unaffected.
- A client approval, a scheduled-post reminder and a publish failure can each arrive on WhatsApp for a
  consenting, opted-in recipient, are logged in `whatsapp_messages`, and update their status from the
  webhook.
- WhatsApp preferences are per-person, default off, and opt-out-able by every role including
  client-portal reviewers.
- No token, app secret, webhook signature, full phone number or message body is ever written to a log.
- `.junie/modules/notifications.md` (and the other touched docs) reflect every shipped step, PHPStan
  level 7 passes, and `composer ci:check` is green on MySQL 8 and the SQLite test database.
```
