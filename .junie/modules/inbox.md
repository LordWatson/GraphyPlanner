# Social Inbox

## Purpose
The social inbox (Phase M-9, `.junie/plans/mvp.md`): one place to handle the comments the public leaves
on a client's published posts, triage them, assign them, and send an **on-brand reply that a human always
sends** — never an auto-reply. Covers M-9.1 (ingestion), M-9.2 (inbox UI, assignment, saved replies,
sentiment, SLA) and M-9.3 (AI draft replies). A `Role::ClientReviewer` never sees the inbox.

Comments come from Upload-Post for seven networks (M-0.2 audit in `publishing.md`): Instagram, Facebook,
TikTok, LinkedIn, YouTube Shorts, Threads and X. **Pinterest and Google Business are
`comments => false`** in `config/platforms.php` and never ingest.

## Models / Tables
- `InboxConversation` (`inbox_conversations`): one thread per root comment on a `post_target`. Holds the
  assignment, triage `sentiment`, workflow `status` (`open`/`snoozed`/`resolved`), `is_reply_needed`, SLA
  stamps (`last_inbound_at`, `first_response_at`, `reminder_sent_at`) and the author. Unique on
  `(social_account_id, external_conversation_id)` for idempotent upserts. `BelongsToOrganization`.
- `InboxMessage` (`inbox_messages`): each comment/reply in a thread. `direction` (`inbound`/`outbound`),
  `body`, per-message `sentiment`, and for outbound replies `sent_by`, `vendor_comment_id`,
  `delivery_status` (`pending`/`sent`/`failed`) + `delivery_error`. Unique on
  `(inbox_conversation_id, external_message_id)`.
- `SavedReply` (`saved_replies`): per-org canned replies, org-wide (`client_id` null) or client-scoped.
- `organizations.inbox_sla_hours`: the inbox's own reply SLA (independent of `approval_sla_hours`).
- `post_targets.comments_synced_at`: when comments were last pulled for a target (sweep ordering/throttle).
- Enums: `InboxStatus`, `CommentSentiment` (precedence: question > negative > positive > neutral;
  `needsReply()` true for question/negative), `MessageDirection`, `MessageDeliveryStatus`.

## Actions / Services / Contracts
- `App\Contracts\CommentSource` → `UploadPostCommentSource` (reads `GET /uploadposts/comments`, replies via
  `POST /uploadposts/comments/create`) / `NullCommentSource` (safe no-op). Its own seam (not `PublishAdapter`
  / `EngagementSource`) so a Meta Graph fallback can slot in; **not** a Phase-4 second adapter. Reads retry
  (429/5xx); the reply uses publish semantics (retry on 429 only) so a reply can't double-post.
- `App\Services\Inbox\SentimentClassifier`: deterministic keyword/punctuation triage — no AI budget spent.
- `App\Services\Inbox\InboxSlaService`: a thread is overdue when open, needs a reply, has no
  `first_response_at`, and its last inbound comment is older than `inbox_sla_hours` (falls back to
  `config('inbox.sla.default_hours')`).
- `App\Actions\Inbox\IngestCommentsForTargetAction`: groups a target's comments into threads by root, upserts
  conversations + messages idempotently (an already-stored outbound reply is left untouched), classifies
  sentiment, rolls the thread's sentiment/reply-needed/timestamps up. `DB::transaction()`, never logs bodies.
- `App\Actions\Inbox\SyncOrganizationCommentsAction`: one org's hourly sweep — recent published targets on
  comment-supporting platforms, oldest-polled first, capped per run, `comments_synced_at` stamped on success
  or failure so a failing post can't starve others. Mirrors the M-3.1 engagement sweep.
- `App\Actions\Inbox\ListInboxConversationsAction`, `AssignConversationAction`,
  `TransitionConversationAction` (open/snooze/resolve/reopen), `SaveSavedReplyAction`.
- `App\Actions\Inbox\DraftInboxReplyAction`: AI draft — `AiAccess` gate, `AiUsageGuard` budget, metered on
  `ai_usage_logs` (method `inbox_reply`), guardrail drops a `never_use` phrase. **Returns text only; never
  persists, never sends.** `App\Contracts\ReplyAssistant` → DeepSeek / Null (same DeepSeek-or-Null binding as
  `WritingAssistant`).
- `App\Actions\Inbox\SendInboxReplyAction`: the **only** caller of `CommentSource::reply()`, only from an
  explicit human request. Records the outbound message `pending`, calls the vendor outside the transaction
  (a failure leaves a visible `failed` row to retry), stamps delivery + the thread's first-response on success.
- Jobs/commands: `SyncOrganizationCommentsJob` + `inbox:sync-comments` (hourly), `SendOverdueInboxRemindersJob`
  + `inbox:send-overdue-reminders` (daily). Both registered in `SchedulerHeartbeat::COMMANDS`.
- Notification: `InboxReplyOverdue` (database, `Comments` category) to the assignee or the studio, once/24h.

## Routes / Controllers
- `GET /inbox` → `InboxController@index` — Inertia `inbox/index` (list + filters + saved replies + counts).
- `GET /inbox/{conversation}` → `@show` — JSON thread detail for the right pane.
- `PATCH /inbox/{conversation}` → `@update` — assign / snooze / resolve / reopen (JSON).
- `POST /inbox/{conversation}/reply` → `@reply` — human send (JSON); 422 on vendor failure.
- `POST /inbox/{conversation}/ai/draft` → `@draft` — AI draft (`throttle:ai`), returns `{text}`.
- `POST /inbox/refresh` → `@refresh` — dispatch the sweep now.
- `POST /saved-replies`, `PATCH|DELETE /saved-replies/{savedReply}` → `SavedReplyController`.
- All under `['portal.redirect','workspace:agency']`. Policies `InboxConversationPolicy`
  (view: Owner/Strategist/Designer/Viewer; act/reply: Owner/Strategist/Designer) and `SavedReplyPolicy`.

## Business Rules
- Tenant isolation: every query is org-scoped (`OrganizationScope`); the policy refuses a bound conversation
  from another org.
- Idempotent ingestion: re-running the sweep never duplicates a comment, and never flips a sent reply inbound.
- A new inbound comment on a resolved thread reopens it.
- AI never sends: drafting returns text only; `SendInboxReplyAction` is the single vendor-reply path and only
  runs from a user request (proven by `AiDraftReplyTest`: drafting makes zero `reply()` calls).
- Sending stamps `first_response_at` (SLA met) and clears `is_reply_needed`; a saved reply's `usage_count` ticks.

## Tests
- `tests/Unit/Inbox/SentimentClassifierTest.php` — the deterministic classifier + enum precedence.
- `tests/Feature/Inbox/CommentIngestionTest.php` — threading, sentiment roll-up, idempotency, platform gating,
  suspended/failed handling.
- `tests/Feature/Inbox/InboxControllerTest.php` — list, assign, resolve/reopen, snooze validation, role gating,
  tenant isolation, saved-reply CRUD.
- `tests/Feature/Inbox/InboxReplyTest.php` — human send (one message, one vendor call), vendor-failure 422 +
  failed row, saved-reply usage, reviewer blocked.
- `tests/Feature/Inbox/AiDraftReplyTest.php` — draft returns text and never sends, guardrail drops a banned
  phrase, over-budget 422, AI-disabled 404.
- `tests/Feature/Inbox/InboxSlaTest.php` — overdue detection + the once-a-day reminder (assignee, else studio).
- Fixtures/doubles: `tests/Feature/Inbox/Concerns/{BuildsInboxFixtures,FakeCommentSource,FakeReplyAssistant}`.

## Known Gaps / Open Questions
- **DMs are deferred** (comments only, per M-0.2) — the `CommentSource` contract is ready for them.
- **Pinterest / Google Business comments deferred** (vendor gap). The Meta Graph `CommentSource` fallback is
  only needed if Upload-Post's IG/FB comments prove unreliable in a live sandbox (still due from M-0.2).
- Vendor comment-endpoint field names follow Upload-Post's docs; a live sandbox check of the exact JSON shape
  is still outstanding (mapping is defensive — first-key-present per field).
- The thread pane links to the original post (thumbnail + caption); a full `SocialPostPreview` of the post in
  the inbox can follow.

## Changelog
- 2026-11-12: Phase M-9 — social inbox shipped (ingestion, triage UI, assignment, saved replies, sentiment,
  SLA + reminders, AI draft replies with human send). New chapter 04 "Inbox"; Clients→05 … Settings→09.
