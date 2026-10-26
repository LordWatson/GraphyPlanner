# Notifications

## Purpose
A cross-cutting notification bell surfaced in both the internal app header and the client portal
header. Built on Laravel's built-in database notifications (`User` already `use`s `Notifiable`),
it alerts a user when something they have a stake in changes elsewhere in the system — a new
comment, a client decision on a post, a post ready for their review, or a failed publish — and,
on click, marks that one notification read and redirects to the page where the underlying entity
can be viewed/acted on.

## Models / Tables
- `notifications` (Laravel's standard polymorphic table, `Illuminate\Notifications\DatabaseNotification`):
  `id` (uuid), `type`, `notifiable_type`/`notifiable_id`, `data` (json), `read_at`. Migration
  `2026_09_19_151337_create_notifications_table`. Reachable via `User::notifications()`/
  `User::unreadNotifications()` (from the `Notifiable` trait already used by `App\Models\User`).

## Phase M-6.7 (I-12.1) — preferences, digest, chat notifier
- `users.notification_preferences` (JSON; migration `2026_11_08_100100`) — per `NotificationCategory`
  (approvals, comments, publishing, invoices, campaigns, other), two channels: `in_app` + `digest`.
  `NotificationPreferences` resolves them with defaults (all in-app on; digest on except comments/
  other; `Other` can't be muted in-app). `PostNotificationService::send` drops a recipient who muted
  a notification's category in-app. Edited at `settings/notifications`
  (`NotificationPreferenceController`, `settings/notifications.tsx`).
- **Daily digest**: `notifications:send-digest` (daily 07:30; `SchedulerHeartbeat::COMMANDS`) →
  `SendNotificationDigestAction` emails each user a `NotificationDigestMail` of the last 24h of their
  stored notifications, filtered to their digest-enabled categories (none → no email). It also calls
  the `ChatNotifier` seam.
- **Notification centre**: `NotificationController::index` is paginated — `?before=<id>` (compound
  `(created_at, id)` cursor) + `?filter=unread`, returning `next_before` / `has_more`. The bell has
  All/Unread tabs and "Load more".
- **`ChatNotifier` contract** (`App\Contracts\ChatNotifier`) + `NullChatNotifier` (bound in
  `AppServiceProvider`) — the swappable team-chat seam. **No Slack**; a WhatsApp implementation drops
  in later. Tests `tests/Feature/Notifications/NotificationPreferencesTest`.

## Phase M-6.5 (Phase 9) — overdue approval reminders
- `approvals:send-overdue-reminders` (daily 09:00; `SchedulerHeartbeat::COMMANDS`) →
  `SendOverdueApprovalRemindersJob`: for every post past its org's approval SLA and not reminded in
  24h (`posts.last_reminded_at`), sends an in-app `ApprovalOverdue` to the studio (Owner/Strategist)
  and, for a `waiting_client` post, a `ClientApprovalReminderMail` (fresh review link) to the client's
  review-email contacts (Step M-6.1). Throttled to once/24h per post, so a daily run never
  double-sends. Tests `tests/Feature/Approvals/ApprovalSlaTest`.

## Actions / Services / Contracts
- `App\Services\PostNotificationService`: resolves the two recipient audiences for post-related
  notifications and sends them, so the same recipient queries aren't duplicated across every
  Action/Job that needs to notify someone:
  - `orgRecipients(Post, ?exclude)`: every internal org user (Owner/Strategist/Designer/Viewer) of
    the post's `org_id`.
  - `clientPortalRecipients(Post, ?exclude)`: every `Role::ClientReviewer` scoped to the post's
    `client_id`.
  - `mentionableRecipients(Post, ?exclude)`: `orgRecipients` merged with `clientPortalRecipients` —
    every user eligible to be `@`-mentioned in a comment on the post, used both to populate the
    mention autocomplete list and to validate mention tokens server-side.
  - `send(Collection $recipients, Notification $notification)`: no-ops on an empty collection,
    otherwise calls `->notify()` on each recipient.
- `App\Notifications\PostCommentAdded`: a new comment was added and is visible to the other side
  of the conversation. Carries `forPortal: bool` so `toArray()`'s `url` points at `posts.edit`
  (org side) or `portal.posts.show` (client-portal side).
- `App\Notifications\PostCommentMentioned`: a user was `@`-mentioned in a comment. Dispatched
  once per mentioned user, independently of `PostCommentAdded`'s "other side" audience. Same
  `forPortal`/`toArray()` shape as `PostCommentAdded`.
- `App\Notifications\PostNoteMentioned`: an org teammate was `@`-mentioned ("tagged") in the note
  attached to a post status change — the transition dialog's note or the Owner's approval-override
  reason (both land on the same transition note). Dispatched once per tagged org recipient from
  `TransitionPostStatusAction`. Constructed with the post, the destination `PostStatus`, and the
  acting user; `toArray()` always links to `posts.edit` (status-dialog tags are internal — no
  `forPortal` variant, and a client-portal user is never tagged this way).
- `App\Notifications\PostApprovalDecided`: a client approved or requested changes on a post.
  `url` → `posts.edit`.
- `App\Notifications\PostWaitingForClientReview`: a post landed in `waiting_client`. `url` →
  `portal.posts.show`.
- `App\Notifications\PostPublishFailed`: a post failed to publish to one or more targets. `url` →
  `posts.edit`.
- `App\Notifications\PostWaitingForInternalReview`: a post landed in `internal_review` and needs a
  studio member to pick it up. `url` → `posts.edit`.
- All of these post notification classes are `via(): ['database']` only (no mail/broadcast channel
  yet) and `toArray()` returns `{title, body, url, post_id}` — `title`/`body` render in the bell
  dropdown, `url` is where `NotificationController::read` redirects.

## Routes / Controllers
- `GET /notifications` → `notifications.index` (`NotificationController::index`) — returns the
  authenticated user's 50 most recent notifications (id/title/body/url/read_at/created_at) plus
  `unread_count`, as plain JSON (not an Inertia render) for the bell dropdown to lazy-load on open.
- `POST /notifications/{notification}/read` → `notifications.read`
  (`NotificationController::read`) — marks that one notification read (only if it belongs to
  `$request->user()`, via `$user->notifications()->whereKey(...)->firstOrFail()` — 404s otherwise)
  and redirects to its stored `url`.
- `POST /notifications/read-all` → `notifications.read-all` (`NotificationController::readAll`) —
  marks every unread notification of the acting user read, redirects back.
- All three routes live in the shared `auth`+`verified` middleware group in `routes/web.php` (not
  the internal-only routes below them, nor the `portal`-guarded group) — the bell is reachable by
  every role, including `Role::ClientReviewer` portal users.

## Business Rules
- **`@`-mentions in comments**: the comment body may contain `@[Name](id)` tokens, inserted by the
  `MentionTextarea` frontend component (`resources/js/components/mention-textarea.tsx`) when a
  user picks someone from the `@` autocomplete dropdown (backed by the `mentionableUsers` prop —
  `PostController::edit`/`PortalPostController::show` — i.e. `PostNotificationService::mentionableRecipients`).
  `App\Support\CommentMentionParser::extractUserIds()` parses those tokens back out;
  `CreatePostCommentAction` cross-checks the IDs against `mentionableRecipients` (never trusting
  the token at face value), records them on `post_comment_mentions` (`PostComment::mentionedUsers()`),
  and sends each one a `PostCommentMentioned` notification — even if the comment is `internal_only`
  or the mentioned user is on the same "side" as the author. `renderCommentBody()` (same frontend
  file) re-renders the tokens as highlighted `@Name` spans wherever a comment is displayed.
- **Who gets notified, per event** (all dispatched from existing Actions/Jobs, not controllers):
  - `CreatePostCommentAction`: if the commenter is a `Role::ClientReviewer`, notifies
    `orgRecipients` with `PostCommentAdded(forPortal: false)`. Otherwise (an org user commented),
    notifies `clientPortalRecipients` with `PostCommentAdded(forPortal: true)` — but only when the
    comment isn't `internal_only` (internal notes must never surface to a client, including via a
    notification). Either way, any `@`-mentioned user is excluded from this "other side" audience
    (they already got a `PostCommentMentioned`) to avoid a duplicate alert.
  - `TransitionPostStatusAction`: on a transition to `internal_review` (from `draft` or
    `changes_requested`), notifies `orgRecipients($post, exclude: $actor)` with
    `PostWaitingForInternalReview` — every studio member except whoever submitted it. In-app only
    (no email), and logged with the recipient count. On a transition to `waiting_client`, notifies
    `clientPortalRecipients` with `PostWaitingForClientReview` (independent of/alongside the
    existing `ClientReviewRequestedMail` email). On a transition to `approved`/`changes_requested`,
    notifies `orgRecipients` with `PostApprovalDecided` — but only when the deciding `$user` is a
    `Role::ClientReviewer` or `null` (the unauthenticated `/review/:token` portal); an internal org
    user moving a post straight to `approved` is not itself a client decision and doesn't notify.
    Additionally, `@[Name](id)` "tags" in the transition note (or the Owner's approval-override
    reason, which lands on the same note) are resolved after commit against `orgRecipients($post,
    exclude: $actor)` only — never the actor, outsiders, or client-portal users — and each tagged
    teammate is sent a `PostNoteMentioned`. Tagged users are then excluded from the broad status
    audience above (via a `whereNotIn('id', …)` on each recipient query) so a tag never arrives
    alongside a duplicate "post needs review" alert — the same de-dup rule `CreatePostCommentAction`
    applies for comment mentions. A `null` actor (review portal) can't tag anyone. The tokens are
    validated exactly like comment mentions (`CommentMentionParser` + a `mentionableRecipients`/
    `orgRecipients` cross-check), never trusted at face value.
  - `PublishPostJob`: when a post ends up `failed` after `PublishAdapter::publish()`, notifies
    `orgRecipients` with `PostPublishFailed`.
- A recipient is never notified about their own action — `orgRecipients`/`clientPortalRecipients`
  both accept an `?exclude` user. The internal-review notification is the one dispatch site where
  the actor is part of the audience, so it passes the actor as `exclude`; the others don't need it
  since their audiences (org vs. client-portal) are already disjoint from the actor.
- `unreadNotificationsCount` is shared on every Inertia response
  (`HandleInertiaRequests::share()`) so the bell's badge is always accurate without a dedicated
  round-trip. Step I-2.6: it's cached per user for 60 s (`App\Services\SharedPropsCache`) and busted
  when a database notification is sent (`NotificationSent` listener in `AppServiceProvider`) or read
  (`NotificationController@read`/`readAll`); the dropdown's own list is fetched lazily from `notifications.index` only once opened
  (`resources/js/components/notification-bell.tsx`, `NotificationBell`), and re-fetches are skipped
  once already loaded for the component's lifetime (cleared back to `null` after "mark all read").
- Clicking a notification (`DropdownMenuItem onSelect`) posts to `notifications.read` via Inertia's
  `router.post`, which marks it read server-side and follows the redirect to the stored `url`
  (`posts.edit` or `portal.posts.show`) — a single action does both "mark read" and "navigate".
- `NotificationBell` is mounted in both `resources/js/components/app-sidebar-header.tsx` (the
  internal app's header, used by `AppSidebarLayout`) and
  `resources/js/layouts/client-portal/client-portal-layout.tsx` (the client portal's header) —
  the same component works for both audiences since it only depends on the shared
  `unreadNotificationsCount` prop and the three routes above (not on any role-specific data).

## Tests
- `tests/Feature/NotificationTest.php`:
  - `test_a_client_comment_notifies_org_users` / `test_a_non_internal_org_comment_notifies_client_portal_users` /
    `test_an_internal_only_org_comment_does_not_notify_client_portal_users` — the three
    `CreatePostCommentAction` notification-routing rules (`Notification::fake()` +
    `assertSentTo`/`assertNotSentTo`).
  - `test_mentioning_a_user_in_a_comment_notifies_them` / `test_mentioning_a_client_user_from_an_org_comment_notifies_them` /
    `test_an_unmentionable_user_id_in_a_comment_body_is_ignored` — `@`-mention parsing/validation
    and `PostCommentMentioned` dispatch, including on an `internal_only` comment.
  - `test_a_post_entering_waiting_client_notifies_client_portal_users` — `TransitionPostStatusAction`
    → `PostWaitingForClientReview`.
  - `test_a_post_entering_internal_review_notifies_studio_members_except_the_actor` /
    `test_the_internal_review_notification_links_to_the_post_editor` /
    `test_moving_a_post_out_of_internal_review_does_not_send_an_internal_review_notification` —
    `TransitionPostStatusAction` → `PostWaitingForInternalReview` (actor, client reviewers and other
    orgs excluded; `url` → `posts.edit`).
  - `test_a_client_approval_decision_notifies_org_users` — `TransitionPostStatusAction` →
    `PostApprovalDecided` when a `Role::ClientReviewer` decides.
  - `test_tagging_a_teammate_in_a_transition_note_notifies_them` /
    `test_a_teammate_tagged_in_a_transition_note_is_not_also_sent_the_broad_status_notification` /
    `test_a_client_reviewer_cannot_be_tagged_in_a_transition_note` /
    `test_an_unmentionable_user_id_in_a_transition_note_is_ignored` /
    `test_tagging_a_teammate_in_an_approval_override_reason_notifies_them` /
    `test_the_transition_tag_notification_links_to_the_post_editor` — `@`-mention "tags" in a
    transition note / override reason → `PostNoteMentioned` (org-only, de-duped from the broad
    status audience, client reviewers and outsiders ignored, `url` → `posts.edit`).
  - `test_clicking_a_notification_marks_it_as_read_and_redirects_to_its_url` —
    `notifications.read` marks `read_at` and redirects to the notification's stored `url`.
  - `test_a_user_cannot_mark_another_users_notification_as_read` — cross-user 404 isolation.

## Known Gaps / Open Questions
- No mail/broadcast channel yet — notifications are database-only (in-app bell). Real-time delivery
  (broadcasting to update the badge without a page navigation) would need a `broadcast` channel and
  a websocket driver (e.g. Reverb), not set up in this repo.
- No notification for an org-internal comment/approval flow that doesn't involve a client (e.g. a
  Designer commenting for a Strategist) — only the client ⇄ org boundary is covered, per the audit
  in the original request (post comments, client approvals/changes-requested). Extending to
  internal-only mentions/assignments is a possible follow-up.
- `PublishPostJob` failures notify every org recipient rather than just the post's `created_by` or
  owning Strategist — broad-but-simple, matching the existing `SendUpcomingPostReminderEmailsAction`
  precedent of emailing "every user of the organization".
- No pagination on `notifications.index` (capped at the 50 most recent) and no "load more" in the
  dropdown — acceptable for now given the expected notification volume, but worth revisiting if it
  grows.

## Changelog
- 2026-10-24: Step M-8.2 — `App\Notifications\ProductFeedbackReceived` (database only), sent to
  every super admin when a partner's staff member submits in-app product feedback; links to the
  super-admin feedback inbox. See `super-admin.md`.
- 2026-10-21: `@`-mention ("tag") support in the post status dialogs. Added
  `App\Notifications\PostNoteMentioned` (database, org-only) and mention resolution/notification in
  `TransitionPostStatusAction` for the transition note and the Owner's approval-override reason;
  tagged teammates are de-duped out of the broad status audience. Frontend: the transition and
  override dialogs' note/reason fields now use `MentionTextarea` (`PostWorkflow` is passed
  `mentionableUsers`), and activity-log notes render tokens via `renderCommentBody`. Tests in
  `tests/Feature/NotificationTest.php`. Shares the comment `@`-mention mechanism (see below).
- 2026-10-21: Step M-3.3 — `ClientReportReady` (database): a client's monthly report draft is ready to review (client owner, else Owners/Strategists).
- 2026-10-20: Phase 18 (grid planner) — `GridPlanStatusChanged` (sent for internal review → team; waiting on client → portal contacts; the client's approval / changes → team), `GridCommentNotification` (mentions and the other side of the conversation; internal notes never reach the client), `GridOrderAtRisk` (to the grid's creator, once). Mail: `ClientGridReviewRequestedMail` ("Your new grid is ready to look at.") and `GridPostsReadyMail` (one email for a grid's captions). `PostNotificationService` recipient helpers accept a `GridPlan` too.
- 2026-10-18: Campaigns enhancement — new notifications under a `Campaigns\CampaignNotification` base: `CampaignDeliverableAssigned` (assigned / due tomorrow / overdue), `CampaignBriefReady`, `CampaignBriefDecided`, `CampaignCommentAdded` (with a plain-text excerpt), `CampaignReviewSubmitted` (one per batch), `CampaignWentLive`. Mail: `CampaignBriefSentMail`, `CampaignPostsReadyMail`, `CampaignReportSharedMail`.
- 2026-10-18: Steps I-2.6/I-2.8 — unread count cached and busted (above); every notification routes
  `database` → `notifications` queue and `mail` → `mail` queue via `viaQueues()`. Tests:
  `tests/Feature/SharedPropsTest.php`, `tests/Feature/QueueInfrastructureTest.php`.
- 2026-10-18: Step I-1.4 — every notification (and every mailable) now implements `ShouldQueue`,
  and every queue connection has `after_commit => true`, so nothing is delivered for a rolled-back
  transaction and a slow mail provider never blocks a request. `PostPublishFailed` is now sent by
  `ResolvePostPublishOutcomeAction` and says "Publish partly failed" for `partially_published`.
- 2026-10-16: Added `App\Notifications\InvoiceIssued` (database channel) for client-portal users of an invoice's client, sent by `InvoiceDeliveryService` when an invoice is sent ("New invoice") or a payment reminder goes out ("Payment reminder"); links to `portal.invoices.show`. See `billing.md`.
- 2026-10-16: Added `App\Notifications\PostWaitingForInternalReview` (database only), sent from
  `TransitionPostStatusAction` to every studio member except the actor when a post lands in
  `internal_review`.
- 2026-09-19: Initial implementation. Added the `notifications` table migration; `App\Services\PostNotificationService`;
  `App\Notifications\{PostCommentAdded,PostApprovalDecided,PostWaitingForClientReview,PostPublishFailed}`;
  dispatch calls from `CreatePostCommentAction`, `TransitionPostStatusAction`, and `PublishPostJob`;
  `NotificationController` (`index`/`read`/`read-all`, routes shared by both the internal app and
  client portal); `unreadNotificationsCount` shared Inertia prop
  (`HandleInertiaRequests::share()`); and the `NotificationBell` component, mounted in
  `app-sidebar-header.tsx` and `client-portal-layout.tsx`.
- 2026-09-19: Added `@`-mention support in post comments. Added the `post_comment_mentions` pivot
  migration + `PostComment::mentionedUsers()`; `App\Support\CommentMentionParser`;
  `PostNotificationService::mentionableRecipients()`; `App\Notifications\PostCommentMentioned`;
  mention resolution/notification in `CreatePostCommentAction` (wrapped in `DB::transaction`); the
  `mentionableUsers` Inertia prop on `PostController::edit`/`PortalPostController::show`; and the
  `MentionTextarea`/`renderCommentBody` frontend component used in the editor's and portal's
  comment forms/lists.

## Phase M-9 — overdue inbox reply reminder
- `App\Notifications\InboxReplyOverdue` (database, `NotificationCategory::Comments`) — tells the assignee
  (or, when unassigned, the studio Owner/Strategist) that a public comment has waited past the org's inbox
  reply SLA. Sent by `SendOverdueInboxRemindersJob` (`inbox:send-overdue-reminders`, daily), throttled to
  once per 24h per thread via `inbox_conversations.reminder_sent_at`. See `inbox.md` and
  `InboxSlaService`. `NotificationCategory::forNotificationClass()` maps any `*Inbox*` notification to the
  Comments bucket, so a user who muted comments isn't nudged.
