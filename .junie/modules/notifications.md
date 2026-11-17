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
- **`ChatNotifier` contract** (`App\Contracts\ChatNotifier`) — the swappable team-chat seam, one
  message to a team's channel. `SlackChatNotifier` is the bound default (`CHAT_NOTIFIER=slack`, safe
  with no webhook URL configured); `CHAT_NOTIFIER=null` binds `NullChatNotifier` and switches it off.
  Tests `tests/Feature/Notifications/NotificationPreferencesTest`.

## WhatsApp notifications (Phase W — Twilio)

`.junie/plans/whatsapp-notifications.md` in full. **Twilio is the provider**, not Meta's Cloud API
direct: a BSP owns the WhatsApp Business Account, the sender registration and the template
submissions, which is most of the work in getting WhatsApp live at all.

### The seam

- `App\Contracts\WhatsAppClient` — `sendTemplate()`, `sendSessionText()` (replies only: valid
  inside the 24-hour window the recipient opened), `verifyCredentials()`. Nothing throws; every call
  returns a `WhatsAppSendResult`, so a WhatsApp failure can never break the thing that triggered it.
- `TwilioWhatsAppClient` is bound by default and **safe unconfigured** — with no credentials every
  send reports not-configured without calling anyone. `WHATSAPP_CLIENT=null` binds
  `NullWhatsAppClient` and switches WhatsApp off everywhere.
- `WhatsAppCredentials` is the one place credentials come from: the organization's own (Step W-4.1,
  encrypted at rest) over the platform's in `config/services.php`. It also holds the two gates —
  `organizations.whatsapp_enabled` and the `Feature::WhatsApp` plan entitlement (W-4.3) — so a
  downgrade or an off switch stops every send without touching a notification.
- `App\Support\WhatsApp\` — `MessageTemplate` (the closed list of messages, below),
  `WhatsAppTemplateMessage` (fills one, and **throws** if the variables do not match the approved
  copy), `WhatsAppSendResult` + `WhatsAppFailure` (transient / permanent / window / not-configured),
  `PhoneNumber` (E.164, and the masked last-4 that is the most any log may say), `TwilioSignature`,
  `WhatsAppRouting` (the single check a `via()` makes).

### Templates (Step W-1.4)

Business-initiated WhatsApp is templates only, so the set of messages is an enum —
`App\Support\WhatsApp\MessageTemplate` — carrying the exact copy submitted for approval next to the
code that fills it. `php artisan whatsapp:templates` prints the submission list and the env var each
approved `ContentSid` goes into (`services.whatsapp.templates.*`). Two guards: a template with no
SID is refused before anything is called, and filling one with the wrong variables is an exception
in a test rather than a `{{2}}` showing through to a client.

### Who can be messaged, and how they consent

| Audience                            | Consent                                                                    |
| ----------------------------------- | -------------------------------------------------------------------------- |
| A member (any role, incl. reviewer) | Per-category opt-in, **off by default**, plus a verified number (W-1.1)    |
| A client contact (no login)         | One consent flag with its source and date, set by Owner/Strategist (W-1.2) |
| Anyone on the suppression list      | Never, whatever the above says (W-1.3)                                     |

- **Preferences** — `NotificationPreferences::CHANNELS` is `in_app`, `digest`, `whatsapp`, with
  `whatsapp` off for every category for everybody and never forced on. `wantsWhatsApp()` requires
  the opt-in **and** a verified number, so neither half can ship alone. `sanitize()` takes the
  stored preferences as a second argument and keeps any channel the payload does not mention —
  without that a save from the settings form would wipe every WhatsApp opt-in.
- **Verification (W-1.1)** — `RequestWhatsAppVerificationAction` sends a six-digit code (hashed at
  rest, ten-minute expiry, five attempts) and `ConfirmWhatsAppNumberAction` checks it.
  `->forget()` removes the number and switches every WhatsApp preference back off, so verifying a
  new number later cannot quietly resume messaging.
- **Suppressions (W-1.3)** — `whatsapp_suppressions` (`org_id` nullable = global). A STOP suppresses
  globally and withdraws every consent held against that number; a START releases the suppression
  and **does not** re-grant consent — that is a decision made in the portal or with the agency, not
  a word in a chat window. A permanently undeliverable send suppresses itself.

### The channel

`App\Notifications\Channels\WhatsAppChannel` (registered with `Notification::extend`) is where every
precondition is checked once, so a new notification cannot forget any of them: a route, an
organization to send as, no suppression, and the organization is not flooding
(`services.whatsapp.per_minute`, default 60 — a ceiling for a fan-out bug, since Twilio paces its
own sending and reports its limits with a retryable code). Every failed check is a silent no-op,
logged at `info` **and** recorded as a `Skipped` `whatsapp_messages` row with the reason.

A notification may declare `toWhatsApp()`; otherwise the channel builds the generic
`MessageTemplate::Notification` message from the `title`/`body`/`url` it already puts in the bell.
(The plan proposed a `SendsWhatsApp` trait for that; it is one fallback in one place, and in the
channel it covers every notification without anyone remembering to `use` it.)

### What can arrive on WhatsApp (Phase W-2)

| Notification                    | Template                  | Audience                           |
| ------------------------------- | ------------------------- | ---------------------------------- |
| `PostWaitingForClientReview`    | `post_waiting_client`     | Client contacts + portal reviewers |
| `UpcomingPublishReminder`       | `post_scheduled_reminder` | Members (`publishing`)             |
| `PostApprovalDecided`           | `post_decided`            | Studio (`approvals`)               |
| `PostPublishFailed`             | `post_publish_failed`     | Studio (`publishing`)              |
| `ApprovalOverdue`               | `approval_overdue`        | Studio (`approvals`)               |
| `InvoiceIssued`                 | `invoice_issued`          | Client contacts (`invoices`)       |
| `CampaignBriefReady`            | `campaign_brief_ready`    | Client contacts (`campaigns`)      |
| Any other campaign notification | `alima_notification`      | Members (`campaigns`)              |
| `DailyDigestReady`              | `daily_digest`            | Members (`other`)                  |
| `StoryPublishHandoffReady`      | `story_ready_to_post`     | The Story's poster (`publishing`)  |

- The client-approval message carries the **same link the email would have given them**: a magic
  portal link for a contact with a login, the token-only `/review/:token` otherwise
  (`SendClientReviewRequestedEmailAction`).
- The scheduled reminder reuses the existing `posts:send-upcoming-reminders` sweep and its
  `reminder_sent_at` guard, so a re-run never double-sends. No new scheduled command.
- `DailyDigestReady` is **not** a `ChatNotifier` implementation, which is what the plan proposed:
  that seam posts one message to an organization's team channel and is addressed to an
  `Organization`, while a digest is addressed to a person with their own preferences and number.
  `SlackChatNotifier` keeps the seam.
- Step S-3.3: `MessageTemplate::StoryReadyToPost` (`story_ready_to_post`, variables `account`,
  `due_at`, `url`; env `TWILIO_TEMPLATE_STORY_READY_TO_POST` →
  `services.whatsapp.templates.story_ready_to_post`). Until its `ContentSid` is set,
  `StoryPublishHandoffReady::toWhatsApp()` sends the generic `alima_notification` template
  instead. The poster is a team member who opted in, or a client contact who consented.

### The message log and the webhook (Step W-3.1)

- `whatsapp_messages` — one row per attempt, including the ones that never left the building
  (`Skipped`, with the reason). `to_phone` is stored in full because that is how Twilio's callback
  matches the row, and is `Hidden` on the model so it cannot reach a prop; `maskedPhone()` is what
  any view shows. The body is not stored — the template plus its variables is a better record and
  does not duplicate client content.
- `POST /webhooks/whatsapp` — status callbacks (`MessageSid` + `MessageStatus`) and inbound messages
  (`From` + `Body`). Fails closed on `X-Twilio-Signature`; out-of-order callbacks cannot walk a
  status backwards; Twilio's 21610 ("recipient opted out") suppresses. See `ops.md` › WhatsApp for
  the middleware exclusions the signature needs.
- Statuses: `WhatsAppMessageStatus` — sent / delivered / read / failed / **skipped**.

### Runbook — switching WhatsApp on for an organization

1. In Twilio: a WhatsApp sender (or a Messaging Service containing one), registered and approved.
2. `php artisan whatsapp:templates` → create each template in the Content Template Builder as a
   `twilio/text` template with the exact copy printed, submit it for WhatsApp approval (hours to
   days), then put each `HX…` id in its env var.
3. Set `TWILIO_STATUS_CALLBACK_URL` to the public `https://…/webhooks/whatsapp`, and point the
   sender's inbound webhook at the same URL (that is what carries STOP).
4. On `settings/organization` (Owner): the Account SID, Auth Token and sender, then **Test
   connection**. Tick "Send WhatsApp messages for this workspace".
5. Each person opts themselves in on `settings/notifications` and verifies their own number.
6. A client's number and consent both live on their **contact**: Clients → the client → **Portal
   access** → Contacts → add or edit → the "Phone (WhatsApp)" field (country code required) and
   "Has agreed to WhatsApp messages". Owner or Strategist, and **only** where they actually said
   yes. There is no WhatsApp number on the client itself — messages go to named people, because
   consent belongs to a person.
7. `settings/whatsapp-log` is where to look when somebody asks whether a message arrived.

Nothing in steps 1–4 sends a message to anybody: with no opt-in and no consent, every send is a
`Skipped` row.

Step S-3.3 added one template to step 2: `story_ready_to_post`
(`TWILIO_TEMPLATE_STORY_READY_TO_POST`). Submit it with the others. It is optional: with the env
var empty, a Story reminder goes out on the generic `alima_notification` template, so only that
one has to be approved before the assisted lane is switched on.

### Tests

`tests/Unit/Services/WhatsApp/TwilioWhatsAppClientTest`,
`tests/Unit/Support/WhatsApp/PhoneNumberTest`,
`tests/Feature/Notifications/WhatsAppChannelTest`,
`tests/Feature/Notifications/WhatsAppVerificationTest`,
`tests/Feature/Notifications/WhatsAppWebhookTest`,
`tests/Feature/Notifications/WhatsAppSettingsTest`.

## Phase M-6.5 (Phase 9) — overdue approval reminders

- `approvals:send-overdue-reminders` (daily 09:00; `SchedulerHeartbeat::COMMANDS`) →
  `SendOverdueApprovalRemindersJob`: for every post past its org's approval SLA and not reminded in
  24h (`posts.last_reminded_at`), sends an in-app `ApprovalOverdue` to the studio (Owner/Strategist)
  and, for a `waiting_client` post, a `ClientApprovalReminderMail` (fresh review link) to the client's
  review-email contacts (Step M-6.1). Throttled to once/24h per post, so a daily run never
  double-sends. Tests `tests/Feature/Approvals/ApprovalSlaTest`.

## Phase S — Stories with extras (assisted lane)

Plan: `.junie/plans/story-extras.md`. The lane itself is in `publishing.md` › "Stories with
extras". This is how the poster hears about a Story that is due.

- `App\Notifications\StoryPublishHandoffReady` (Step S-3.1). Queued, `ShouldBeEncrypted` (the
  handoff link carries a token, so it must not sit readable in a queue or `failed_jobs`). The class
  name carries `Publish`, which puts it in the `publishing` preference category. Constructor: the
  handoff id, the link, `late`, `nudge`. `via()` by notifiable:

    | Notifiable                         | Channels                                    |
    | ---------------------------------- | ------------------------------------------- |
    | A team member (`User`)             | bell + mail, plus WhatsApp if they opted in |
    | A member who muted `publishing`    | mail only (the email always goes)           |
    | An address (`Notification::route`) | mail                                        |
    | A client contact (`ClientContact`) | WhatsApp only, and only with consent        |

    Unlike a publish failure, agencies get the email too. The bell `url` is the signed-in
    `posts.story-handoff.open` route, never a token link. The mail view
    `resources/views/emails/story-handoff-ready.blade.php` lists the account, the due time in the
    account's timezone and the extras; for a client recipient it carries the agency's branding
    (`BrandingResolver`), for a team member Alima's. Titles: "Time to post." / "Still to post."
    (nudge); a late notification says when the Story was due.

- `App\Actions\Stories\NotifyStoryPosterAction` (called by the sweep after it stamps the handoff)
  decides who:

    | Handoff                   | Recipients                                                                           |
    | ------------------------- | ------------------------------------------------------------------------------------ |
    | `team`, with a poster     | that member                                                                          |
    | `team`, nobody assigned   | every Owner, Strategist and Designer of the organization                             |
    | `client`                  | review-email contacts and portal reviewers by email; consenting contacts by WhatsApp |
    | `client`, nobody to reach | falls back to the team, with a warning in the log                                    |

    One link is issued per call and shared by every recipient. It also sends the push and, on the
    first notification only, the `story.ready_to_post` webhook (`WebhookEvent::StoryReadyToPost`;
    the payload has the signed-in open URL, no token).

- **Push (Step S-3.2).** `App\Actions\Push\SendStoryHandoffPushAction`: tag
  `story-handoff-{id}`, so the nudge replaces the first notification on the lock screen. Team
  recipients only; a client recipient gets no push. See "Web push" below for the opt-in.
- **Morning heads-up (Step S-3.4).** `SendUpcomingPostReminderEmailsAction` reads the
  organization's assisted Stories (`handoff_required`, `pending`, on a `scheduled` post) due in its
  local today straight from `post_targets`, independent of `reminder_sent_at`: a Story reminded
  about days ahead still has to be announced on its day. Guarded once per organization per local
  day by the cache key `story-heads-up:{org_id}:{Y-m-d}` (36 hours). When nothing else is new, a
  stories-only mail is still sent. Only while the kill switch is on. `UpcomingPostReminderMail`
  takes `$storyTimes` and `$storiesOnly` and exposes `$storyHeadsUp` ("2 stories to post by hand
  today (10:00, 16:30).") and `$headline`; `UpcomingPublishReminder` takes `$storiesByHand` and
  folds it into the WhatsApp `post_summary`.
- `NotificationCategory::Publishing->description()` now reads "A post fails to publish, or a story
  is ready for you to post." (Step S-3.5).
- `AlertOnFailedPublishingJob::queueOf()` no longer calls `unserialize()` on a payload that is not
  a serialized object. An encrypted job's command is ciphertext, and reading it must not replace
  the real failure with a second one.

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
- Phase S (Stories with extras):
    - `tests/Feature/Stories/StoryHandoffNotificationTest.php` — recipients per handoff type and
      the fallback to the team; bell + mail for a member, WhatsApp only on opt-in, mail still sent
      with the bell muted; an address gets mail and a contact gets WhatsApp only with consent; the
      notification is queued, encrypted and in the `publishing` category; the stored bell payload
      points at the signed-in route and holds no token; late and nudge wording; the mail renders
      the account, the extras and the link; the generic template until the dedicated one is
      configured; the link is the same each time it is issued; the push (poster's subscriptions only,
      tag reuse, `Gone` pruned, no-op when unconfigured); no log line contains the token; the
      webhook fires on the first notification only.
    - `tests/Feature/Stories/AgencyPushOptInTest.php` — an agency member subscribes and
      unsubscribes on the URL business browsers already use; a client reviewer and a guest
      cannot; the `pwa` prop is shared with an agency member and not with a client reviewer; the
      "goes out soon", "nothing scheduled tomorrow" and publish-failure pushes still skip a
      subscribed agency member.
    - `tests/Feature/Stories/StoryMorningHeadsUpTest.php` — the count and times, the singular,
      the organization's own day, nothing while the lane is off, nothing on a second run, a Story
      reminded days ahead still announced on its day, the stories-only mail, a Story no longer
      waiting left out, and the WhatsApp summary with and without Stories.
    - `tests/Feature/Business/PushRemindersTest.php` was updated for the renamed routes.

## Known Gaps / Open Questions

- Phase S (2026-11-13): a client recipient of a Story handoff gets email and WhatsApp, not push or
  a bell item. `story_ready_to_post` still needs external template approval; the generic template
  is used until its `ContentSid` is set. The morning heads-up guard is a cache key, so a cache
  flush on the day can send it twice.

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

- 2026-11-13: Phase S — Stories with extras (assisted lane). New `StoryPublishHandoffReady`
  (bell + mail + WhatsApp, encrypted, no token in the bell), `NotifyStoryPosterAction`,
  `SendStoryHandoffPushAction`, the `story_ready_to_post` WhatsApp template, the morning heads-up
  in the upcoming-post reminder, and the `story.ready_to_post` webhook. Push opt-in opened to
  agency members (`business.push.*` renamed `push.*`). See the Phase S section above.
- 2026-11-07: **Team chat actually sends now, and bounced addresses stop receiving mail.**
  `ChatNotifier` had only a no-op binding, so every caller believing it told the team something was
  talking to nothing. New `SlackChatNotifier` (per-org `organizations.chat_webhook_url`, encrypted;
  works for Teams too), bound by `config('notifications.chat')`, with a settings field, a "Send test
  message" button and a "Turn off" button. Announced through the single `PostNotificationService::send()`
  seam and limited to the five moments somebody must act on — post waiting on client, approved,
  changes requested, publish failed, approval overdue — because a channel that reports everything
  gets muted. New `suppressed_emails` + `ResendWebhookController` (Svix signature, fails closed) and
  a `MessageSending` listener that drops suppressed recipients from every mail path at once. New
  `mail:check-dns` reports SPF/DKIM/DMARC on the sending domain. Tests:
  `TeamChatNotificationTest` (15), `EmailSuppressionTest` (12), `EmailAuthenticationCheckTest` (8).
  Audit findings 10 and 17.
- 2026-10-27: Step CG-1.3 (competitor-gap) — real web-push delivery + two new pushes. Added
  `MinishlinkWebPushNotifier` (`minishlink/web-push ^11.0`) behind a `push.transport` config switch
  (default `log` keeps dev/tests on the no-op logger), `SendPublishFailurePushAction` (business orgs,
  from `ResolvePostPublishOutcomeAction`) and `SendNothingScheduledTomorrowPushAction` +
  `push:send-empty-tomorrow` (daily). See "Web push" above. Tests: `PushNotificationsTest`,
  `MinishlinkWebPushNotifierTest`. New config: `push.transport`. New dependency: `minishlink/web-push`.
  The VAPID key generation and the Android-Chrome / iOS-Safari device check are owner handoff items.
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

## Web push (Step B-7.4 / CG-1.3)

Browser push for business/creator workspaces is a **separate seam** from the in-app bell above — it
is not a Laravel `Notification`, but the `App\Contracts\PushNotifier` contract (`send(PushSubscription,
PushMessage): PushDeliveryResult`). Delivery is bound by `config('push.transport')`:

- `log` (default) → `App\Services\Push\WebPushNotifier` — logs the intent and returns `Failed`, so the
  whole opt-in/reminder pipeline runs dependency-free in dev and tests.
- `webpush` → `App\Services\Push\MinishlinkWebPushNotifier` (**CG-1.3**, `minishlink/web-push`
  `^11.0`) — real VAPID delivery, mapping the push service's response to `Delivered` / `Gone`
  (404/410 → the caller prunes the subscription) / `Failed`. Never throws for a dead endpoint and
  never logs a key or the payload. The `WebPush` client is built in a `protected makeWebPush()` so a
  test can double it without the crypto extensions (`tests/Unit/Services/Push/MinishlinkWebPushNotifierTest`).
  Bound in `AppServiceProvider` by the config switch; tests fake the contract via `app->instance`.

Pushes (each an Action that builds a `App\Support\Push\PushMessage`, loops the workspace's
subscriptions and prunes `Gone`; runs only when `isConfigured()`):

- `SendPostPushRemindersAction` (B-7.4) — "your post goes out at …" (every 5 min).
- `SendPublishFailurePushAction` (**CG-1.3**) — "a post failed to publish", dispatched from
  `ResolvePostPublishOutcomeAction`'s after-commit beside the `PostPublishFailed` bell, **business orgs
  only** (agency keeps email + bell). Resolved from the container so the bound transport/fake is used.
- `SendNothingScheduledTomorrowPushAction` + `push:send-empty-tomorrow` (**CG-1.3**, daily 18:00,
  `SchedulerHeartbeat`) — nudges onboarded business orgs whose brand has a connected account but
  nothing scheduled tomorrow (brand timezone). Idempotent per org per local day (a cache guard).
  Tests: `tests/Feature/Business/PushNotificationsTest`.
- `SendStoryHandoffPushAction` (**S-3.2**) — "Time to post. @handle's story is ready.", to the
  Story's team poster(s). The one push that goes to **agency** members.

**Opt-in opened to agencies (Step S-3.2).** The routes moved out of the business group and were
renamed `business.push.*` → `push.subscribe` / `push.unsubscribe` (same URL, `POST` / `DELETE
/push/subscribe`, `PushSubscriptionController`). They sit behind `org.member` + `portal.redirect`,
so every member of an agency or business workspace can subscribe and a client reviewer cannot. The
`pwa` shared prop (`HandleInertiaRequests::sharedPwa`) is now sent to every member except an
agency's client reviewers. `PushRemindersCard workspace="agency"` on Settings → Notifications
("Story reminders on this phone"); `use-push.ts` registers the service worker when an agency
member opts in, because only the business shell registers it on every page. The three older pushes
above still filter on business organizations (`AgencyPushOptInTest`). `public/sw.js`: a
notification click reuses a same-origin window when one is open and opens a new one otherwise.

## Phase M-9 — overdue inbox reply reminder

- `App\Notifications\InboxReplyOverdue` (database, `NotificationCategory::Comments`) — tells the assignee
  (or, when unassigned, the studio Owner/Strategist) that a public comment has waited past the org's inbox
  reply SLA. Sent by `SendOverdueInboxRemindersJob` (`inbox:send-overdue-reminders`, daily), throttled to
  once per 24h per thread via `inbox_conversations.reminder_sent_at`. See `inbox.md` and
  `InboxSlaService`. `NotificationCategory::forNotificationClass()` maps any `*Inbox*` notification to the
  Comments bucket, so a user who muted comments isn't nudged.

## Mention alerts

- `App\Notifications\MentionAlert` (database, `NotificationCategory::Comments` — the class name
  carries "Mention") — a word the studio watches has appeared in a newly ingested comment. Sent to
  the Owners/Strategists by `App\Services\Inbox\MentionAlerts`, once per match, from comment
  ingestion after its transaction commits. Carries the term, the author and a link to the inbox;
  **not** the comment body. See `inbox.md` (`MentionAlerts`, `MentionKeyword`).
