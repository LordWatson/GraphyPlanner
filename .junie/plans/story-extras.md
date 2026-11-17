# Graphy — Story Extras Plan (links and stickers on Instagram Stories)

_Written 2026-11-13, against the code as it stands at commit `62f611f` (hand-off at scheduling time,
Step I-3.6 / M-2.5; per-channel formats, M-2.3; web push, B-7.4 / CG-1.3; WhatsApp, Phase W)._

Agencies want Instagram Stories with a tappable link, a poll, a mention or a location sticker.
**Instagram's publishing API cannot add any of them**, for Upload-Post or for any other vendor: a
Story published through the API is plain media. Nor can a Story be edited after it is live, and
there is no draft state an outside tool can write to. So a Story with a sticker has exactly one
legitimate route to Instagram: a person presses share inside the Instagram app.

This plan makes that route as short as it can be. Plain Stories keep publishing automatically. A
Story with **extras** takes an **assisted lane**: Alima plans it, gets it approved, holds it,
tells the right person the moment it is due, hands the media and the link to their phone, and
records the result. The person does the last few taps in Instagram.

This file sits alongside `.junie/plans/graphy-development-steps.md`, `improvements.md`, `mvp.md`
and `whatsapp-notifications.md`. How to use it (same rules as those plans):

- Each step is sized to hand to Junie / Claude Code as one unit of work: a goal, the files it
  touches, the detail, the tests, and a **Done when**. Use the `implement-plan-step` skill.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), one-job Actions in `App\Actions\Stories\*` / `App\Actions\Publishing\*`, reusable
  logic in `App\Services\*`, `DB::transaction()` around multi-write logic, logging at boundaries
  with ids (**never** a handoff token, a signed URL, or a link's query string), and PHPUnit
  coverage (class-based, extending `Tests\TestCase`: happy path, role rejections, edge cases, a
  regression test for any bug).
- **Every step updates the module docs** in the same change: primarily
  `.junie/modules/publishing.md` and `.junie/modules/posts.md`, plus `notifications.md`,
  `client-portal.md`, `home.md`, `calendar.md`, `clients.md`, `api.md`, `mcp.md` and `ops.md`
  where a step touches them. Use the `sync-module-docs` skill.
- PHPStan level 7 passes with no new ignores or baseline entries. SQL works on MySQL 8 **and** the
  in-memory SQLite test database. Run the `graphy-checks` skill before finishing a step.
- Frontend steps follow `.junie/brand-guidelines.md` and use the `graphy-frontend-page` skill
  (house components, semantic tokens, Wayfinder routes, `can`-gated UI). Status is always colour
  **plus** a label through `<StatusPill>`. Signal Orange is never a status colour.
- Any new scheduled command is added to `App\Support\Scheduling\SchedulerHeartbeat::COMMANDS` and
  wrapped with `$safely(...)` in `routes/console.php` in the same change, or `SchedulerSafetyTest`
  fails.
- Step ids are prefixed `S-` (e.g. `Step S-2.1`) so they never collide with `Step 2.1`, `I-`, `M-`,
  `B-`, `C-`, `CG-`, `W-`, `GTM-` or `MCP-`. Mark finished steps `✅ Done` with an "Implemented
  via …" note.
- Priority tags: **[MVP]** needed before the feature can be switched on for a real org · **[V1]**
  makes it good · **[Stretch]**.

---

## 0. Built 2026-11-13 — and what changed from the plan

Everything except three steps is built, on branch `assisted-story-v2`. The lane ships **switched
off** (`STORY_HANDOFF_ENABLED=false`), because the one step nothing else can stand in for has not
been run.

**Not done.**

- **S-0.1, the share-sheet spike on real phones.** It needs an iPhone and an Android phone in a
  hand. The throwaway spike page was not built: the real handoff page (S-4.3) already branches on
  `navigator.canShare` and falls back to "Save media", so it is the thing to test. To run it: set
  `STORY_HANDOFF_ENABLED=true` on a deployed (HTTPS) environment, schedule a Story with a link for a
  few minutes ahead on a test account, open the notification on each device in the matrix, and fill
  in the table under S-0.1. Go → leave the flag on. No-go → turn it off again and rethink before
  anyone relies on it.
- **S-6.1 (auto-confirm through Meta Graph)** and **S-6.3 (Facebook Stories)**. Both say "verify
  first" against Meta's own documentation and app scopes, and neither is needed for the lane to
  work. Facebook is a one-line config change (`publishing.story_handoff.platforms`) once its
  sticker list has been checked.
- **The Playwright smoke test** for the handoff page (S-4.3 says "if the setup covers public
  pages"; the e2e harness runs two named journeys and the page is covered by Vitest and PHPUnit
  instead). And the dedicated WhatsApp template (S-3.3) is in the code but **not approved**: until
  `TWILIO_TEMPLATE_STORY_READY_TO_POST` holds its ContentSid, the generic template is used.

**Changed from the plan, and why.**

1. **The kill switch stays off by default** (S-6.4 said to flip it). With S-0.1 unproven, on by
   default would put the fallback Alex rejected as "too manual" in front of real users.
2. **`PostPolicy::postStoryByHand`, not `PostPolicy::update`** (S-4.5). `update` is false for a post
   that is going out, which is exactly when a Story needs posting. Owner, Strategist and Designer
   may open a handoff or mark it posted; a Viewer and a client reviewer may not. For the same
   reason a Viewer is never the poster (D7): assigning one falls through to the creator, then to
   everyone who can act.
3. **An extra is "added" when its key is present, even blank** (4.2). The editor autosaves
   half-typed work, so request validation only bounds the shape, and the blocking `story_extras`
   checklist item is what stops an unfinished extra being scheduled. The API and MCP treat a null
   key as "not added", since nobody is mid-typing there.
4. **The editor posts a channel's extras as one JSON string** (it is a real HTML form, and an
   empty mentions list has no other encoding). `validated()` drops an empty `story_extras: {}`, so
   the request puts it back (`ValidatesStoryExtras::restoreStoryExtras()`).
5. **Late confirmation works on both paths** (S-2.4 offered a choice). A `missed` handoff can be
   confirmed from its link while it still opens, and from the editor's "Mark as posted", as long
   as the channel is still failed for that reason on the same attempt. The post moves through the
   same `failed`/`partially_published` → `publishing` edge that "Retry failed channels" uses.
6. **An edit keeps an open handoff; a recall closes it** (S-2.5). `VendorScheduleService::recall()`
   takes `keepHandoffs` for the edit path. A channel whose lane changes while its post is going
   out (extras added or removed) is sent again, so it ends up with a handoff or with the vendor.
7. **`handoffState()` is null for a draft.** "Needs posting by hand" shows on lists once the post is
   scheduled, as the S-5.1 table says; the editor shows it from the first extra.
8. **The morning heads-up has its own once-a-day guard** (S-3.4 said to ride `reminder_sent_at`).
   The sweep reminds three days ahead, so a Story stamped on Wednesday would have had no line on
   Friday. It is now a cache key per organization per local day, and a stories-only email is sent
   when nothing else is new.
9. **A second failure reason, `StoryProblemReported`**, beside `StoryNotPosted` (S-2.3), so a
   poster's note does not read as "Instagram rejected this post".
10. **The poller skips a channel only while it has an open handoff** (S-2.3 said "skip
    `handoff_required`"). A Story that went to the vendor after all (S-6.2), or lost its handoff,
    must still time out.
11. **An assistant cannot set `if_missed`** over MCP (S-5.4 / S-6.2). "Publish it plain if nobody
    posts" is a person's standing instruction. The REST API accepts it.
12. **The queued notification is encrypted** (`ShouldBeEncrypted`) and `SecretScrubber` strips
    long path tokens from error reports (S-6.4), so a token is not readable in a queue, a
    failed-jobs row or Sentry.
13. **"I've posted it" asks once if nothing was shared from the page first.** One stray thumb on a
    full-width button would otherwise publish the channel as posted.
14. **Housekeeping found on the way.** `bootstrap/cache/routes-v7.php` was a stale route cache that
    hid every new route; it was cleared. Dates are `CarbonImmutable` app-wide, so Story code types
    them as `CarbonInterface`. `AlertOnFailedPublishingJob` no longer unserializes an encrypted job
    payload.

15. **The link is derived, not random, and does not rotate** (D6 said a random token, re-minted on
    each send). `StoryHandoff::plainToken()` is an HMAC of the handoff id and its publish attempt
    under the app key; only its `sha256` is stored. A re-minted token made the first email stop
    working the moment the nudge went out, or a teammate opened the Story from the bell. One link
    per handoff; a new attempt is a new link. It opens for 30 hours after the later of the due
    time and the handoff's creation, so a Story retried days later still gets a usable link.
16. **The token routes suspend the tenant scopes** (`TenantScopes::suspended()`). The security
    review found that someone signed in to a different organization who opened a link would have
    had the post scoped away mid-confirmation, leaving it stuck in `publishing`. The token is the
    credential there; a signed-in visitor is recorded as the confirmer only if they belong to the
    handoff's organization.
17. **A paused handoff still expires** (S-2.3 said skip archived clients and suspended
    organizations). It is not notified or nudged, but when its window closes the channel fails
    loudly instead of waiting in `publishing` for ever.
18. **Moving a Story's time while its handoff is open re-opens it** for the new time (old one
    cancelled, new attempt). And switching the kill switch off leaves a Story that is already
    awaiting its handoff alone, so it is neither stranded nor auto-published without its extras.
19. **The media route is stricter than S-4.2 asked.** Only JPEG, PNG, WebP, GIF, MP4, QuickTime
    and WebM are served (no SVG or HTML from an upload), a very large image is served uncropped
    rather than decoded in a web request, and a remote disk's non-seekable stream is not
    range-served (the whole file, `Accept-Ranges: none`).
20. **The sweep isolates each handoff.** One that throws is logged, reported and counted as
    `failed`; the rest still run.

**Known limits** (also in `publishing.md`): a handoff's link is shared by everyone it was sent to
and cannot be revoked for one person without closing the handoff; client recipients get email and
WhatsApp, not push or a bell item; "Posted" is the poster's word; cropped images are rendered per
request; rotating `APP_KEY` breaks open links (the bell route re-derives them).

---

## 1. Product framing

### 1.1 The promise

> Schedule the Story on Wednesday. On Friday your phone tells you it's ready. Three taps later
> it's live, with the link.

### 1.2 The journey (what we are building)

**Wednesday: create and schedule**

1. A strategist creates a post with an Instagram channel set to the **Story** format and attaches
   the media.
2. On that channel they open **Story extras** and add a link (URL plus sticker text) and, if they
   want, a mention, a poll, a location or a hashtag, with a short placement note.
3. The channel's pill changes from "Auto-publish" to **"Needs posting by hand"**, with one line:
   "Instagram only allows links and stickers from its own app. We'll get it ready and tell you
   when it's time."
4. They choose who posts it: **the team** (the post's assignee) or **the client**. The client's
   default is remembered.
5. They schedule the post. Every other channel goes to Upload-Post straight away, as today. The
   Story channel stays in Alima.

**Wednesday to Thursday: approval**

6. The client reviews as usual. The review page and the portal show the Story with its extras
   listed beside the preview, so the link and sticker text are approved with the creative.

**Friday: posting**

7. The morning reminder email says "1 story to post by hand today at 10:00."
8. At 10:00 the poster gets a notification on their phone (push, WhatsApp if they opted in, email,
   and the bell): "Time to post. @clientname's story is ready."
9. Tapping it opens the **handoff page**. No login. It shows the account to post to, the media and
   the extras as a short checklist.
10. They tap **Share to Instagram**. Alima copies the link and passes the media to Instagram's
    Story editor through the phone's share sheet.
11. In Instagram they add the link sticker, paste, place it, and share.
12. Back in Alima they tap **Posted**.

**Afterwards**

13. The channel is Published, the post resolves through the normal pipeline, and the activity log
    records who posted it and when.
14. If nobody confirms inside the window, the poster gets one nudge. After the window the channel
    fails with a plain reason and the post lands in Needs attention, where it can be retried.

### 1.3 Principles

- **Two lanes, one pipeline.** An assisted channel is still a `PostTarget` on a normal `Post`. It
  moves through the same statuses, the same resolver, the same retry. Nothing forks.
- **Never silent.** An assisted Story is either confirmed, or it fails loudly. It never sits
  pending forever and it never quietly auto-publishes without its extras unless a human chose that
  in advance (Step S-6.2).
- **The user chooses knowingly.** Adding an extra shows the trade-off. Removing every extra returns
  the channel to auto-publish.
- **Alima never touches Instagram's app.** No browser automation, no unofficial APIs, no stored
  Instagram passwords. That is what keeps client accounts safe.
- **AI never publishes.** Nothing here changes that. The fallback in S-6.2 is a human's standing
  instruction on one post, not a decision the system makes.

### 1.4 What Alima cannot do (say this in the UI and in support docs)

- It cannot add the sticker. The poster does, in Instagram.
- It cannot check where the sticker was placed or that the right link was pasted.
- It cannot be sure the Story is live. "Posted" is the poster's word, unless Step S-6.1 (auto
  confirm) lands and proves workable.
- An assisted Story has no `post_url` or `platform_post_id`, so per-post engagement metrics for it
  are "not available" until S-6.1 resolves the media id.

### 1.5 Scope

- **In:** Instagram Stories with extras. The data model is keyed on the `story` format so
  Facebook Stories can follow (S-6.3) without a migration.
- **Out:** TikTok, Reels music, product tags, collaborator invites through this lane. A native
  mobile app. Link-in-bio as a substitute (decided against, 2026-11-13: it forces accounts to
  operate in a way they may not want).

---

## 2. What the code does today (verified 2026-11-13)

Read these before starting. Each claim was checked in the file named.

| Fact                                                                                                                                                                               | Where                                                                                         |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Scheduling a post queues `SendScheduledPostToVendorJob` (undelayed) **and** a delayed `PublishPostJob` at the earliest channel time.                                               | `TransitionPostStatusAction::dispatchPublishJob()`                                            |
| The hand-off job sends every unsent channel due more than `LEAD_MINUTES` (5) ahead, per channel, through `PublishPostJob::dispatchTargets()`. The post stays `scheduled`.          | `App\Jobs\SendScheduledPostToVendorJob::claim()`                                              |
| At the post's time `PublishPostJob` moves it to `publishing`, gives unsent channels a fresh `publish_attempt_id` and calls `dispatchTargets()`.                                    | `App\Jobs\PublishPostJob::claim()` / `handle()`                                               |
| `dispatchTargets()` is the single fan-out, also used by `RetryFailedPostTargetsAction` and `VendorScheduleService::resubmit()`.                                                    | `PublishPostJob::dispatchTargets()`                                                           |
| A channel is `pending`, `published` or `failed`. Accepted-by-vendor is still `pending` with an `external_post_id`.                                                                 | `App\Enums\PostTargetStatus`, `PublishPostTargetJob`                                          |
| `SyncPublishStatusAction($target, bool $ok, ?string $error)` marks a channel published or failed and resolves the post.                                                            | `App\Actions\Publishing\SyncPublishStatusAction`                                              |
| `ResolvePostPublishOutcomeAction` finalises a `publishing` post once no channel is `pending`: all published, none (failed), or a mix (`partially_published`).                      | `App\Actions\Publishing\ResolvePostPublishOutcomeAction`                                      |
| `PollPendingPublishStatusesAction` fails any `pending` channel on a `publishing` post after `publishing.delivery_timeout_hours` (24), whether or not it has an `external_post_id`. | `App\Actions\Publishing\PollPendingPublishStatusesAction`                                     |
| `Post::canBeRecalled()` needs at least one vendor-held channel and every channel held or failed.                                                                                   | `App\Models\Post::canBeRecalled()`                                                            |
| The Story format exists for Instagram and Facebook (`vertical`, `crop_to` 9:16, one image or video, ≤60s).                                                                         | `config/platforms.php` (`$story`)                                                             |
| A channel's format is `post_targets.format`, resolved by `PostTarget::resolvedFormat()`.                                                                                           | `App\Models\PostTarget`                                                                       |
| A post has `assignee_id`, `created_by`, `link_url`. A channel has a `link_url` override (`effectiveLink()`).                                                                       | `App\Models\Post`, `App\Models\PostTarget`                                                    |
| The checklist's soft `links` item warns "the link won't be posted" for Instagram.                                                                                                  | `EvaluatePostChecklistAction::channelChecks()`                                                |
| Public token links are hashed (`sha256`), 64 random chars, expiring, and throttled by `throttle:public-token`.                                                                     | `CreateReviewTokenAction`, `routes/web.php` (`review/{token}`)                                |
| Web push exists behind `App\Contracts\PushNotifier`, with a service worker and subscriptions per user. **The opt-in is business-workspace only today.**                            | `routes/web.php` (`push/subscribe`), `push-reminders-card.tsx`, `SendPostPushRemindersAction` |
| WhatsApp delivery exists through `WhatsAppRouting::with()` and `MessageTemplate`. New templates need external approval.                                                            | `App\Notifications\PostPublishFailed`, `.junie/modules/notifications.md`                      |
| A notification's preference category comes from its class name. `Publish` in the name puts it in `publishing`.                                                                     | `App\Enums\NotificationCategory::forNotificationClass()`                                      |
| The app is a PWA with a manifest, a service worker and an Android Trusted Web Activity wrapper.                                                                                    | `public/manifest.webmanifest`, `public/sw.js`, `android/README.md`                            |
| Per-channel editor fields (first comment, link, format) live in one component.                                                                                                     | `resources/js/components/post-editor/target-fields.tsx`                                       |

**Not verified. Confirm in code before relying on it:**

- How `UpdatePostAction` / `StorePostRequest` / `UpdatePostRequest` validate and persist per-channel
  fields (the rules weren't under `targets.*` where I looked).
- How `FormatSpec` exposes its key (`story`). `resolvedFormat()` returns a `FormatSpec`; find the
  property or compare through `PlatformCapabilities`.
- How `SendClientReviewRequestedEmailAction` resolves client contacts, to reuse for "the client
  posts" in S-3.1.
- The post resource shape in the public API and the MCP server (S-5.4).
- Whether the story format is ever inferred on "Auto", or only when chosen explicitly.

---

## 3. Design decisions

Read these as settled unless a step's implementation proves one wrong. If it does, record the
change in section 0 of this file, the way `whatsapp-notifications.md` does.

### D1. The handoff is not a `PublishAdapter`

`PublishAdapter` has one global binding and its `publish()` means "a vendor accepted this". A
handoff has no vendor, no `external_post_id` and no webhook. Making it an adapter would need a
per-channel adapter resolver that nothing else wants. Instead the split happens at the one fan-out
(`PublishPostJob::dispatchTargets()`) and the handoff reuses the pipeline's own outcome seam
(`SyncPublishStatusAction`).

### D2. An assisted channel is `pending` with an open handoff

No new `PostTargetStatus`. While the poster hasn't confirmed, the channel is `pending` with no
`external_post_id`, so the post stays `publishing`, exactly as it does while a vendor is processing.
The UI derives "Ready to post" from the open `StoryHandoff` row. Confirm → `published`. Missed →
`failed`. `partially_published` and "Retry failed channels" then work with no changes.

### D3. `handoff_required` is stored, and set in one place

Whether a channel takes the assisted lane is `format is story AND it has at least one extra`. The
two job queries need it in SQL, so it is a boolean column on `post_targets`, written **only** by
the action that saves a channel's extras (S-1.2). A unit test asserts the column always equals the
derived value.

### D4. The handoff row is created when the post starts publishing, and notified by a sweep

`PublishPostJob` fires at the post's **earliest** channel time. A post with a feed channel at 09:00
and an assisted Story at 10:00 would otherwise hand off an hour early. So `dispatchTargets()`
creates the handoff row with `due_at = scheduled_at_utc`, and a per-minute command sends the
notification once `due_at` has passed. The same command nudges and expires. One idempotent sweep,
no delayed notifications to chase when something changes.

### D5. A handoff belongs to one publish attempt

`story_handoffs.publish_attempt_id` copies the channel's attempt id. A retry or a re-claim mints a
new attempt id, which makes the old handoff stale: its token stops working and the sweep ignores
it. This is the same guard `PublishPostTargetJob` already uses.

### D6. Tokens follow the review-token pattern

64 random characters, `sha256` at rest, returned once at creation, expiring, throttled by
`throttle:public-token`, scoped to one channel. A token never reveals another post, another client
or any billing data.

### D7. The poster

`team` → the post's `assignee_id`, falling back to `created_by`, falling back to
`PostNotificationService::orgRecipients()`. `client` → the client's contacts and portal reviewers,
resolved the way the review-request email resolves them. The channel stores the choice
(`post_targets.handoff_recipient`, nullable). Null means the client's default
(`clients.story_handoff_recipient`, default `team`).

### D8. No feature gate for MVP

The assisted lane ships to every plan. It is a workaround for a platform limit, not a premium
feature. Revisit only if a pricing reason appears.

---

## 4. Data model

### 4.1 `post_targets` (new columns)

| Column              | Type                                | Notes                                             |
| ------------------- | ----------------------------------- | ------------------------------------------------- |
| `story_extras`      | `json`, nullable                    | The extras for this channel. Null or `{}` = none. |
| `handoff_required`  | `boolean`, default `false`, indexed | D3.                                               |
| `handoff_recipient` | `string(16)`, nullable              | `team` / `client`. Null = client default. D7.     |

### 4.2 `story_extras` shape (v1)

```json
{
    "version": 1,
    "link": { "url": "https://example.com/offer", "text": "Shop now" },
    "mentions": ["clienthandle"],
    "hashtag": "summersale",
    "location": "Leeds, United Kingdom",
    "poll": { "question": "Which one?", "options": ["Blue", "Green"] },
    "placement_note": "Link sticker bottom centre, under the price.",
    "if_missed": "fail"
}
```

- Every key except `version` is optional. An extras object with only `placement_note` or
  `if_missed` does **not** count as "has extras".
- `link.url`: `https` only, ≤2048 chars. `link.text`: ≤30 chars, optional.
- `mentions`: 1 to 5 handles, stored without `@`, `[A-Za-z0-9._]{1,30}`.
- `hashtag`: one tag, stored without `#`.
- `location`: free text ≤80. The poster picks the real place in Instagram.
- `poll.options`: 2 to 4, each ≤25 chars. `poll.question`: ≤80.
- `if_missed`: `fail` (default) or `publish_plain` (S-6.2 only; ignore until then).
- Represented in PHP by a readonly DTO, `App\Support\Publishing\StoryExtras`, with
  `fromArray()`, `toArray()`, `isEmpty()`, and a `checklist(): list<array{key,label,value}>` the
  handoff page and the review page both render from.

### 4.3 `story_handoffs` (new table)

| Column                 | Type                              | Notes                                                              |
| ---------------------- | --------------------------------- | ------------------------------------------------------------------ |
| `id`                   | bigint                            |                                                                    |
| `org_id`               | foreignId, indexed                | `BelongsToOrganization`, same scoping as `posts`.                  |
| `client_id`            | foreignId, indexed                | For client-scoped queries and the portal.                          |
| `post_id`              | foreignId, cascade                |                                                                    |
| `post_target_id`       | foreignId, cascade                |                                                                    |
| `publish_attempt_id`   | `string(36)`                      | D5. Unique with `post_target_id`.                                  |
| `token_hash`           | `string(64)`, unique              | D6.                                                                |
| `recipient_type`       | `string(16)`                      | `team` / `client`, resolved at creation.                           |
| `user_id`              | foreignId, nullable, nullOnDelete | The team poster, when there is one.                                |
| `due_at`               | datetime, indexed                 | The channel's `scheduled_at_utc`.                                  |
| `notified_at`          | datetime, nullable                | Set by the sweep.                                                  |
| `nudged_at`            | datetime, nullable                | Set by the sweep.                                                  |
| `opened_at`            | datetime, nullable                | First view of the handoff page.                                    |
| `shared_at`            | datetime, nullable                | First tap on Share / Save media.                                   |
| `completed_at`         | datetime, nullable                |                                                                    |
| `completed_by_user_id` | foreignId, nullable, nullOnDelete | Null when confirmed by token only.                                 |
| `completed_via`        | `string(16)`, nullable            | `link` (handoff page) / `staff` (editor button) / `auto` (S-6.1).  |
| `outcome`              | `string(24)`, nullable            | `posted` / `missed` / `cancelled` / `published_plain` / `problem`. |
| `problem_note`         | `string(500)`, nullable           | From "Something went wrong".                                       |
| `expires_at`           | datetime, indexed                 | `due_at` + window.                                                 |
| timestamps             |                                   |                                                                    |

An **open** handoff is `completed_at IS NULL AND outcome IS NULL`. Model
`App\Models\StoryHandoff` with `scopeOpen()`, `isOpen()`, `isCurrent()` (its attempt id still
matches the channel's), `post()`, `target()`, `user()`, `client()`.

### 4.4 `clients` (new column)

`story_handoff_recipient` `string(16)` default `team`. D7.

### 4.5 Config (`config/publishing.php`, new `story_handoff` block)

```php
'story_handoff' => [
    'window_minutes' => (int) env('STORY_HANDOFF_WINDOW_MINUTES', 120),  // due → expiry
    'nudge_after_minutes' => (int) env('STORY_HANDOFF_NUDGE_MINUTES', 15),
    'token_ttl_hours' => (int) env('STORY_HANDOFF_TOKEN_TTL_HOURS', 30),  // Story lives 24h; a little slack
    'late_grace_minutes' => 10,  // a sweep running late still notifies, flagged as late
],
```

---

## Phase S-0 — Prove it on real phones

Nothing else is worth building until this passes. The whole "three taps" promise rests on the
phone's share sheet handing a file to Instagram's Story editor.

### Step S-0.1 — Share-sheet spike [MVP] — ⏳ Not run (needs real phones — see section 0)

**Goal.** Know, per device, whether `navigator.share({ files })` from Alima reaches Instagram's
Story editor, and what the fallback feels like.

**Build.** A throwaway page, super-admin only, at `super-admin/labs/share-test` (or a local-only
route). It offers one 9:16 JPEG and one 9:16 MP4 (≤60s) and three buttons:

1. **Share image** — fetch the file as a blob on page load, then on tap:
   `navigator.clipboard.writeText(url)` followed by
   `navigator.share({ files: [new File([blob], 'story.jpg', { type: 'image/jpeg' })] })`.
2. **Share video** — the same with `video/mp4`.
3. **Save media** — an `<a download>` to the same file.

Show `navigator.canShare?.({ files })`, the user agent, and any thrown error on screen.

Rules that matter and must be honoured in the real page later:

- The blob is fetched **before** the tap. `share()` must run inside the user gesture, and a fetch
  in between can burn the activation.
- Clipboard write comes first, share second, both in the same handler.
- The media response is same-origin (or CORS-enabled) with a correct `Content-Type`.

**Test matrix.** Record results in a table in this file under "S-0.1 results".

| Device / browser                | Image → Instagram Story | Video → Instagram Story | Clipboard survives | Notes |
| ------------------------------- | ----------------------- | ----------------------- | ------------------ | ----- |
| iPhone, Safari                  |                         |                         |                    |       |
| iPhone, Alima PWA (home screen) |                         |                         |                    |       |
| iPhone, Chrome                  |                         |                         |                    |       |
| Android, Chrome                 |                         |                         |                    |       |
| Android, Alima TWA (Play build) |                         |                         |                    |       |
| Android, Samsung Internet       |                         |                         |                    |       |

**Go / no-go.**

- **Go:** image and video reach the Story editor on iPhone Safari and Android Chrome. Build as
  planned.
- **Partial:** one platform needs Save media. Build as planned, with the handoff page leading with
  whichever action works on that device (S-4.3 already branches on `canShare`).
- **No-go:** neither platform shares to Stories. Stop and rethink before S-1. The fallback
  (save, open Instagram, pick from camera roll) is the flow Alex rejected as too manual on
  2026-11-13, so do not ship it as the main path without his say-so.

**Done when.** The table is filled in from real devices, the decision is written under it, and the
spike route is deleted.

---

## Phase S-1 — Extras on the post

After this phase a Story channel can carry extras, they validate, they show in the editor, the
review page and the portal, and `handoff_required` is correct. Nothing about publishing changes
yet, so **this phase must ship together with Phase S-2** or behind the flag in S-1.1. Otherwise a
Story with extras would auto-publish plain.

### Step S-1.1 — Kill switch [MVP] ✅ Done

_Implemented via `config/publishing.php` (`story_handoff`), `PlatformCapabilities::supportsStoryExtras()`, the `story_extras` flag on the shared `platforms` prop. Default stays `false` (section 0)._

**Goal.** One config flag that turns the whole lane off.

- `config('publishing.story_handoff.enabled')`, env `STORY_HANDOFF_ENABLED`, default `false` until
  S-4 is done, then `true`.
- When off: the editor hides Story extras, saving ignores submitted extras, and
  `handoff_required` is always written `false`. Existing rows are left alone.
- Share it to the frontend through the existing `platforms` / shared props mechanism. Do not add a
  new global prop if a fitting one exists.

**Tests.** With the flag off, a submitted `story_extras` is not stored and the channel is not
flagged.

**Done when.** The flag exists, is documented in `ops.md`, and is covered.

### Step S-1.2 — Columns, DTO and the one writer [MVP] ✅ Done

_Implemented via migration `2027_05_01_100100`, `StoryExtras`, `PostTarget`, `SyncTargetStoryExtrasAction`, the writers in `UpdatePostAction` / `DuplicatePostAction` / `RecyclePostAction`, factory states._

**Goal.** Store extras and keep `handoff_required` honest.

**Files.**

- Migration: the three `post_targets` columns (4.1) and `clients.story_handoff_recipient` (4.4).
- `App\Support\Publishing\StoryExtras` (4.2).
- `App\Models\PostTarget`: fillable, casts (`story_extras` → `array`, `handoff_required` →
  `boolean`), `storyExtras(): StoryExtras`, `needsHandoff(): bool`, `handoffRecipient(): string`
  (the override, else the client default).
- `App\Actions\Stories\SyncTargetStoryExtrasAction`: given a channel and raw input, normalise
  through the DTO, store it, and set `handoff_required = flag on && format is story && ! extras->isEmpty()`.
  Call it from wherever channels are saved today (`CreatePostAction`, `UpdatePostAction`,
  `DuplicatePostAction`, `RecyclePostAction`, bulk create/import if they copy channel fields).
  Find every writer of `post_targets.format` with a grep and make sure each one re-runs this
  action, because changing the format away from Story must clear the flag.
- `PostTargetFactory`: a `story()` state and a `withStoryExtras()` state.

**Details.**

- Changing a channel's format away from `story` keeps the stored extras (the user may switch back)
  but clears `handoff_required`.
- Do **not** reuse `link_url` as the sticker link. `effectiveLink()` feeds caption composition and
  the `links` checklist item for every platform. The editor may prefill the sticker URL from it
  (S-1.4), but they are separate values.

**Tests.** `tests/Unit/Support/Publishing/StoryExtrasTest` (normalising, `isEmpty` with only a note,
handle and hashtag stripping). `tests/Feature/Stories/StoryExtrasPersistenceTest`: create and
update store extras; format switch clears the flag and switching back restores it; duplicate and
recycle carry extras; an invariant test that walks a set of factory states and asserts
`handoff_required === $target->needsHandoff()`.

**Done when.** Extras round-trip, the flag is right in every writer, PHPStan passes.

### Step S-1.3 — Validation and the checklist [MVP] ✅ Done

_Implemented via `ValidatesStoryExtras`, `UpdatePostRequest`, `EvaluatePostChecklistAction` (`story_extras`, `story_handoff`, the `links` regression)._

**Goal.** Bad extras never reach a schedule.

**Files.** `StorePostRequest`, `UpdatePostRequest` (and any per-channel request), a reusable rule
set in `App\Http\Requests\Concerns\*` if that is where shared rules live,
`EvaluatePostChecklistAction`.

**Details.**

- Request rules per 4.2. Reject extras on a channel whose platform has no `story` format.
- Checklist, new **blocking** item `story_extras` ("Story extras are complete"): a link without a
  valid `https` URL, a poll with fewer than two options, an empty mention.
- Checklist, new **soft** item `story_handoff` ("Someone can post this by hand"): warns when
  the recipient is `team` and the post has no `assignee_id` ("No one is assigned, so everyone on
  the team will be asked"), or when the recipient is `client` and the client has no contact to
  notify.
- Existing soft `links` item: when the channel is a Story with a link sticker, do not emit "the
  link won't be posted". Add a regression test, because that message would now be wrong.

**Tests.** Request tests for each rule. Checklist tests for the two new items and the suppressed
warning. A ClientReviewer cannot submit extras (they cannot edit posts at all; assert it).

**Done when.** A post with broken extras cannot move to `scheduled`.

### Step S-1.4 — Editor UI [MVP] ✅ Done

_Implemented via `story-extras-panel.tsx` + `story-extras/*`, `lib/story-extras.ts`, `target-fields.tsx`, `live-checklist.ts`, preview chips._

**Goal.** Add, edit and remove extras on a Story channel, and understand the consequence.

**Files.** `resources/js/components/post-editor/target-fields.tsx`, a new
`resources/js/components/post-editor/story-extras-panel.tsx`,
`resources/js/components/post-editor/types.ts`, `readiness-checklist.tsx`, the preview panel.

**Details.**

- The panel appears only when the channel's resolved format is `story` and the flag is on.
- Fields: Link (URL, sticker text), Mention, Hashtag, Location, Poll (question, 2 to 4 options),
  Placement note. Each extra is added from an "Add" menu, not shown empty by default.
- "Use the post's link" button prefills the sticker URL from the channel's effective link.
- The moment the channel has one extra:
    - Pill: `<StatusPill>` "Needs posting by hand" (a neutral or informational tone from the
      semantic tokens, with the label; never Signal Orange).
    - Helper: "Instagram only allows links and stickers from its own app. We'll get this ready and
      tell you when it's time to post."
    - "Who posts it": Team / Client, defaulting from the client. When Team, show the assignee's
      name, or "Everyone on the team" when unassigned.
- Removing the last extra returns the pill to "Auto-publish".
- Preview panel: list the extras under the phone preview as chips ("Link: Shop now", "Poll"). Do
  not try to draw fake stickers on the media in MVP.
- Copy follows the Alima voice. Sentence case. Short.

**Tests.** A Vitest component test beside the existing ones (`readiness-checklist.test.tsx` is the
pattern): panel hidden for a feed format, pill flips on first extra and back on last removal, poll
option bounds.

**Done when.** A strategist can build the Friday example from section 1.2 in the editor.

### Step S-1.5 — Review page, portal and client default [MVP] ✅ Done

_Implemented via `StoryChannelPresenter::clientStory()`, `story-extras-note.tsx` on the review link and the portal, the client edit form._

**Goal.** The client approves the extras with the creative, and an agency can set who posts.

**Files.** `ReviewController` and `resources/js/pages/review/show.tsx`,
`Portal\PortalPostController` and `resources/js/pages/portal/posts/show.tsx`, the client settings
form and its request/action.

**Details.**

- Both client-facing pages render `StoryExtras::checklist()` read-only beside the Story preview,
  with a line: "This story has a link and stickers, so it will be posted by hand at the scheduled
  time."
- When the recipient is `client`, say so plainly: "We'll send you a link to post it yourselves."
- Client settings: "Stories with links and stickers are posted by: Our team / The client".
  Owner and Strategist only.
- Role scoping is unchanged: a ClientReviewer sees extras only for their own client's posts, from
  "Waiting on client" onwards, and no billing-restricted data.

**Tests.** Review-token and portal feature tests assert the extras payload is present for the
client's post and absent for another client's. Settings test for role gating.

**Done when.** Approval covers the link and sticker text.

---

## Phase S-2 — The assisted lane in the publish pipeline

After this phase an assisted channel is held back from Upload-Post, gets a handoff row at publish
time, can be confirmed or expired, and resolves the post through the existing actions. No
notifications and no page yet: tests drive the actions directly.

### Step S-2.1 — Keep assisted channels away from the vendor [MVP] ✅ Done

_Implemented via `SendScheduledPostToVendorJob`, `PublishPostJob::dispatchTargets()`, `PublishPostTargetJob::claim()`._

**Goal.** An assisted channel is never sent to Upload-Post.

**Files.** `App\Jobs\SendScheduledPostToVendorJob`, `App\Jobs\PublishPostJob`,
`App\Jobs\PublishPostTargetJob`.

**Details.**

- `SendScheduledPostToVendorJob::claim()`: add `->where('handoff_required', false)` to the channel
  query. A post whose only channel is assisted then logs "no channel far enough ahead" and
  returns, which is correct.
- `PublishPostJob::dispatchTargets()`: partition the collection. `handoff_required` channels go to
  `App\Actions\Stories\OpenStoryHandoffAction` (S-2.2). The rest are batched as today. If the
  vendor partition is empty, do not dispatch an empty batch.
- `PublishPostTargetJob::claim()`: a belt-and-braces guard. If the locked channel has
  `handoff_required`, log a warning and return null without stamping `publish_requested_at`. This
  is the regression net for any future caller that bypasses `dispatchTargets()`.

**Tests.** `tests/Feature/Stories/AssistedLanePipelineTest`:

- Scheduling a mixed post (feed + assisted Story) sends one channel to the vendor
  (`Http::fake`, assert one request) and leaves the Story with no `external_post_id`.
- Scheduling a Story-only assisted post makes no vendor request at all.
- A plain Story (no extras) still goes to the vendor with `media_type=STORIES`. Regression.
- `PublishPostTargetJob` dispatched directly for an assisted channel makes no vendor request.

**Done when.** No test path can send an assisted channel to Upload-Post.

### Step S-2.2 — The handoff model and opening one [MVP] ✅ Done

_Implemented via migration `2027_05_01_100200`, `StoryHandoff`, `OpenStoryHandoffAction`, `ResolveStoryPosterAction`, `IssueStoryHandoffLinkAction` (a derived, stable link — section 0, item 15)._

**Goal.** At publish time each assisted channel gets exactly one open handoff.

**Files.** Migration for `story_handoffs` (4.3), `App\Models\StoryHandoff`, its factory,
`App\Actions\Stories\OpenStoryHandoffAction`, `App\Actions\Stories\ResolveStoryPosterAction`,
config block (4.5).

**Details. `OpenStoryHandoffAction(PostTarget $target): StoryHandoff`**

- Inside `DB::transaction()`: lock the channel. If it isn't `pending`, has an `external_post_id`,
  or lacks a `publish_attempt_id`, log and return without creating anything.
- Mark any other open handoff for this channel `outcome = cancelled` (a stale attempt).
- `firstOrCreate` on (`post_target_id`, `publish_attempt_id`). `PublishPostJob` retries and
  re-claims, so this must be idempotent.
- `due_at = scheduled_at_utc ?? now()`. `expires_at = max(due_at, now()) + window_minutes`.
- Mint the token like `CreateReviewTokenAction` (64 random, `sha256`). The plain token is **not**
  stored. Because the sweep (S-2.3) sends the notification later and needs a URL, generate the
  link at send time instead: see "Token at send time" below.
- Resolve the poster through `ResolveStoryPosterAction` (D7) and store `recipient_type` and
  `user_id`.
- Log `info` with `post_id`, `post_target_id`, `story_handoff_id`, `org_id`, `due_at`. Never the
  token.

**Token at send time.** A hashed token cannot be read back, and the notification is sent by a later
process. Do this: the row is created with a random placeholder hash, and
`App\Actions\Stories\IssueStoryHandoffLinkAction(StoryHandoff): string` mints a fresh token,
overwrites `token_hash`, and returns the URL. It is called by the sweep when notifying or nudging,
and by the authenticated "Open handoff" button (S-4.5). Each issue invalidates the previous link,
which is acceptable: the newest notification always works. Cover that in a test and say it in
`publishing.md`.

**Tests.** Opening twice for one attempt yields one row. A new attempt cancels the old row and
opens a new one. Poster resolution: assignee, then creator, then null (everyone). Client recipient.

**Done when.** `dispatchTargets()` on an assisted channel leaves one open, correctly addressed
handoff and a still-`pending` channel.

### Step S-2.3 — The sweep: notify, nudge, expire [MVP] ✅ Done

_Implemented via `stories:process-handoffs`, `ProcessStoryHandoffsAction`, `ExpireStoryHandoffAction`, `PublishFailureReason::StoryNotPosted`, the poller change._

**Goal.** One per-minute command drives every time-based change to a handoff.

**Files.** `App\Console\Commands\ProcessStoryHandoffsCommand` (`stories:process-handoffs`),
`App\Actions\Stories\ProcessStoryHandoffsAction`, `App\Actions\Stories\ExpireStoryHandoffAction`,
`routes/console.php`, `SchedulerHeartbeat::COMMANDS` (`interval_minutes` 1), `ops.md`.

**Details.** For open, current handoffs (`isCurrent()`: attempt id matches, channel still
`pending`, post still `publishing`), walking with `lazyById()`:

1. **Skip paused.** If `PublishPostJob::pausedReason($post)` is non-null (archived client,
   suspended or deleted org), do nothing. The handoff resumes if the hold lifts inside its window,
   and expires normally if not.
2. **Notify.** `due_at <= now` and `notified_at` null → stamp `notified_at` first, inside a
   transaction with a row lock, then dispatch the notification (S-3.1) after commit. Stamp-then-send
   so a crash can't double-notify. If `now > due_at + late_grace_minutes`, pass `late: true` so the
   message says "This was due at 10:00".
3. **Nudge.** `notified_at <= now - nudge_after_minutes`, `nudged_at` null, `opened_at` null or
   `shared_at` null → stamp and send the nudge.
4. **Expire.** `expires_at <= now` → `ExpireStoryHandoffAction`.

Handoffs that are not current (stale attempt, post no longer `publishing`) are closed as
`cancelled` without touching the channel.

**`ExpireStoryHandoffAction`.** In a transaction: lock the handoff, re-check it is open and
current, set `outcome = missed`, `completed_at = now()`. Then call
`SyncPublishStatusAction($target, false, __('Nobody confirmed this story was posted. Post it now or retry to send a new reminder.'))`,
which fails the channel and resolves the post. (`if_missed = publish_plain` is handled in S-6.2.)

**Also in this step.**

- `PollPendingPublishStatusesAction`: skip channels with `handoff_required = true`. Otherwise the
  generic 24-hour timeout could beat a long custom window and write the wrong error. Add a test.
- `App\Enums\PublishFailureReason`: add `StoryNotPosted` with a plain-language reason and fix
  ("Open the post and choose Retry to send a new reminder, or mark it as posted if it went out"),
  and map the expiry error to it wherever errors are classified.

**Tests.** `tests/Feature/Stories/ProcessStoryHandoffsTest` with `Carbon::setTestNow`: not notified
before due; notified once at due across two runs; late flag; nudge once; expiry fails the channel
and resolves a Story-only post to `failed` and a mixed post to `partially_published`; paused org is
skipped; stale attempt is cancelled without failing the channel. `SchedulerSafetyTest` still
passes.

**Done when.** A handoff left alone goes notify → nudge → missed, exactly once each.

### Step S-2.4 — Confirming a handoff [MVP] ✅ Done

_Implemented via `CompleteStoryHandoffAction` (late confirmation on both paths), `ReportStoryHandoffProblemAction`._

**Goal.** "Posted" publishes the channel through the normal seam.

**Files.** `App\Actions\Stories\CompleteStoryHandoffAction`,
`App\Actions\Stories\ReportStoryHandoffProblemAction`.

**Details. `CompleteStoryHandoffAction(StoryHandoff, ?User $by)`**

- Transaction, lock the handoff and the channel. Must be open and current, else throw a
  validation error ("This link is no longer active. Open the post in Alima to see where it's up
  to.").
- Set `outcome = posted`, `completed_at`, `completed_by_user_id`.
- `SyncPublishStatusAction($target, true)`. That sets `published_at` and resolves the post.
- Activity log: add an entry in the same style the post's log uses for status changes, naming who
  confirmed (or "via the handoff link" when `$by` is null) and that it was posted by hand. Find how
  `TransitionPostStatusAction` writes its note and reuse that path so the history drawer shows it.
- Log `info` with ids.

**`ReportStoryHandoffProblemAction(StoryHandoff, string $note, ?User $by)`**: sets
`outcome = problem`, stores the note, fails the channel through `SyncPublishStatusAction` with
"The poster reported a problem: {note}". The post resolves and the existing `PostPublishFailed`
notification tells the team.

**Late confirmation.** A poster may post on time but tap "Posted" after expiry. Allow
`CompleteStoryHandoffAction` on a handoff whose `outcome` is `missed` **if** the channel is still
`failed` with the `StoryNotPosted` reason and the post is `failed` or `partially_published`: flip
the channel to `published` and move the post with the same edges `RetryFailedPostTargetsAction`
relies on. If that proves awkward against `PostStatusTransitionMap`, implement it instead as a
staff-only "Mark as posted" button in the editor (S-5.2) and leave the token path strict. Record
which way it went.

**Tests.** Confirm publishes a Story-only post; confirm on a mixed post waits for the vendor
channel and then resolves; double confirm is a no-op with a friendly error; confirm on a stale
attempt is refused; problem report fails the channel with the note and notifies.

**Done when.** The actions alone can take an assisted Story from `publishing` to `published`.

### Step S-2.5 — Retry, unschedule, reschedule, delete [MVP] ✅ Done

_Implemented via `Post::canBeRecalled()`, `VendorScheduleService::recall()`, `CancelStoryHandoffsAction`, `AssistedLaneLifecycleTest`._

**Goal.** Every existing way to change a post behaves sensibly with an open handoff.

**Details and tests, one per behaviour.**

- **Retry failed channels.** `RetryFailedPostTargetsAction` mints a new attempt id and calls
  `dispatchTargets()`, which now opens a fresh handoff due immediately. Assert a new notification
  is due on the next sweep and the old handoff is `cancelled`.
- **Before publish time** (post `scheduled`): there is no handoff row yet, so unschedule,
  reschedule, edit and delete need no changes for the assisted channel. Assert a mixed post still
  recalls its vendor-held channel and that the assisted one simply moves.
- **After publish time** (post `publishing`, handoff open): `Post::canBeRecalled()` is false today
  because the assisted channel is neither vendor-held nor failed. Extend the rule: a channel with
  an open handoff counts as recallable. In `VendorScheduleService::recall()`, close its handoff as
  `cancelled` and reset the channel the way a recalled vendor channel is reset. Keep the "refused =
  nothing changes" guarantee for the vendor channels.
- **Delete / archive client / suspend org.** `DeletePostAction` cascades the rows.
  `releaseScheduled()` paths: a paused handoff is skipped by the sweep (S-2.3). Assert no
  notification is sent for an archived client.
- **Editing extras while a handoff is open.** The handoff page reads live data, so an edit shows
  immediately. No special handling. Assert the page payload reflects an edit.

**Done when.** `VendorHeldPostsTest` and `ScheduledPostHandOffTest` still pass untouched, and the
new cases above are covered in `tests/Feature/Stories/AssistedLaneLifecycleTest`.

---

## Phase S-3 — Telling the poster

### Step S-3.1 — The notification [MVP] ✅ Done

_Implemented via `StoryPublishHandoffReady`, `NotifyStoryPosterAction`, `emails/story-handoff-ready.blade.php`._

**Goal.** At due time the poster hears about it on every channel they have.

**Files.** `App\Notifications\StoryPublishHandoffReady` (the word `Publish` puts it in the
`publishing` preference category), a mail view following the existing notification mails,
`App\Actions\Stories\NotifyStoryPosterAction`.

**Details.**

- Constructor: the handoff id, the plain URL from `IssueStoryHandoffLinkAction`, `late`, `nudge`.
  Queued (`ShouldQueue`), with `viaQueues()` like `PostPublishFailed`.
- `via()`: `['database', 'mail']` wrapped in `WhatsAppRouting::with(...)`. Unlike publish failures,
  agencies get the **email too**: this is time-critical and the bell alone is not enough.
- Bell (`toArray`): title "Time to post.", body "@handle's story is ready.", `url`, `post_id`.
  Late: "This story was due at 10:00." Nudge: "Still to post: @handle's story."
- Mail: one screen, one button ("Open the story"), the account handle, the due time in the
  recipient's timezone, and the extras list. Alima voice.
- WhatsApp (`toWhatsApp`): use the generic `MessageTemplate::Notification` (`alima_notification`)
  for MVP. A dedicated template is S-3.3.
- **Recipients.** `team` with a `user_id` → that user. `team` without → `orgRecipients($post)`.
  `client` → the contacts resolved like the review-request email, using the mail/WhatsApp routes
  that flow already uses for contacts without a login (an on-demand notification if that is how
  it's done there; follow the existing pattern, don't invent one).
- **The link in the bell** for a signed-in team member should go to the authenticated route
  (S-4.5), not carry a token in a stored notification row. Mail, WhatsApp and push carry the token
  URL. Never write the token URL to the `notifications` table or to logs.

**Tests.** `Notification::fake()`: right recipients per recipient type; channels; preference
respected for WhatsApp; the database payload contains no token; late and nudge wording.

**Done when.** The sweep from S-2.3 sends this and a test proves who gets what.

### Step S-3.2 — Web push for agency members [MVP] ✅ Done

_Implemented via `SendStoryHandoffPushAction`, the push opt-in opened to agency members (`push.subscribe`), Settings → Notifications card._

**Goal.** The phone buzzes. Email is too slow for "post this now".

Push is business-workspace only today (`routes/web.php` comment, `push-reminders-card.tsx`
`isBusiness` gate, and `SendPostPushRemindersAction` filtering `type = business`).

**Details.**

- Open the push opt-in (`push.subscribe` / `push.unsubscribe`, `StorePushSubscriptionRequest`,
  `PushRemindersCard`) to agency members. Card copy for agencies: "Get a nudge on this phone when
  a story is ready to post." Do **not** widen the existing "goes out soon" and "nothing scheduled
  tomorrow" pushes to agencies; they stay business-only. Check each of those actions still filters
  on org type after the opt-in is opened.
- `App\Actions\Push\SendStoryHandoffPushAction(StoryHandoff, string $url, bool $nudge)`: builds a
  `PushMessage` (title "Time to post.", body "@handle's story is ready.", `url`, `tag`
  `story-handoff-{id}` so a nudge replaces the first notification), loops the recipient users'
  subscriptions, prunes `Gone`. No-op unless `isConfigured()`. Called from
  `NotifyStoryPosterAction` after commit.
- The service worker's `notificationclick` already opens `url`; confirm it focuses an existing
  Alima window when there is one.
- Client recipients have no push in MVP (portal users could follow later).

**Tests.** Follow `tests/Feature/Business/PushNotificationsTest`: an agency member can subscribe;
the handoff push goes to the assignee's subscriptions only; a `Gone` subscription is deleted;
business-only pushes are still not sent to agency orgs (regression).

**Done when.** An agency member who opted in gets a push at due time.

### Step S-3.3 — Dedicated WhatsApp template [V1] ✅ Done

_Implemented via `MessageTemplate::StoryReadyToPost` and its env key. The template still has to be submitted and approved; the generic one is used until then._

Add `MessageTemplate::StoryReadyToPost` (`story_ready_to_post`) with variables `account`,
`due_at`, `url`, register it in the template registry (Step W-1.4) and switch
`StoryPublishHandoffReady::toWhatsApp()` to it. Template approval is external and takes days, so
the generic template stays as the fallback until it is approved. Update the runbook in
`notifications.md`.

### Step S-3.4 — Morning heads-up [V1] ✅ Done

_Implemented via `SendUpcomingPostReminderEmailsAction` with its own once-a-day guard (section 0, item 8)._

`posts:send-upcoming-reminders` (daily 08:00) already emails members a digest. Add a line and a
count for assisted Stories due that day: "2 stories to post by hand today (10:00, 16:30)." Query
`handoff_required` channels on `scheduled` posts due in the recipient's day. No new command, no new
stamp column: it rides on the existing sweep and its `reminder_sent_at` guard. Also pass the count
into `UpcomingPublishReminder` so the WhatsApp reminder can mention it if the template allows.

### Step S-3.5 — Preferences copy [V1] ✅ Done

_Implemented via `NotificationCategory::Publishing->description()`._

`NotificationCategory::Publishing->description()` currently reads "A post fails to publish or
needs attention." Change to cover this: "A post fails to publish, or a story is ready for you to
post." Check the settings page renders it.

---

## Phase S-4 — The handoff page

### Step S-4.1 — Routes, controller and payload [MVP] ✅ Done

_Implemented via `StoryHandoffController`, `ShowStoryHandoffAction`, the `story-handoff.*` routes, `TenantScopes` (section 0, item 16)._

**Goal.** A token opens one Story's handoff, and nothing else.

**Files.** `routes/web.php` (inside the existing `throttle:public-token` group, next to
`review/{token}`), `App\Http\Controllers\StoryHandoffController`,
`App\Http\Requests\Stories\*`, `App\Actions\Stories\ShowStoryHandoffAction`.

**Routes.**

| Method | URI                     | Name                    | Purpose                            |
| ------ | ----------------------- | ----------------------- | ---------------------------------- |
| GET    | `story/{token}`         | `story-handoff.show`    | The page.                          |
| GET    | `story/{token}/media`   | `story-handoff.media`   | The file to share.                 |
| POST   | `story/{token}/shared`  | `story-handoff.shared`  | Stamps `shared_at`.                |
| POST   | `story/{token}/posted`  | `story-handoff.posted`  | `CompleteStoryHandoffAction`.      |
| POST   | `story/{token}/problem` | `story-handoff.problem` | `ReportStoryHandoffProblemAction`. |

**Details.**

- Resolve by `hash('sha256', $token)`. Unknown, expired (`token_ttl_hours` past `due_at`) or
  cancelled → the same friendly page as an expired review link, never a stack trace and never a
  hint about whether the token ever existed.
- A completed handoff still opens, read-only, showing "Posted at 10:04 by Sam" (or "Marked as
  missed"). That stops a second teammate posting it again.
- Stamp `opened_at` on first view.
- Payload (and **only** this): account handle and platform, client name, due time with timezone,
  `StoryExtras::checklist()`, media kind, the media URL (`story-handoff.media`), state
  (`ready` / `posted` / `missed` / `cancelled`), who is expected to post, and the placement note.
  No caption (Stories have none), no other channels, no other posts, no invoice or billing data,
  no internal comments.
- Response headers: `X-Robots-Tag: noindex`, no caching of the page.
- If the visitor happens to be signed in as a member of the org, pass `can.openPost` and the
  editor URL so they can jump to the post.

**Tests.** `tests/Feature/Stories/StoryHandoffPageTest`: valid token renders the payload; wrong
token 404-style page; token for client A never exposes client B; payload has no billing keys;
completed handoff is read-only; throttle middleware is applied (assert on the route's middleware).

**Done when.** The page data is correct and scoped.

### Step S-4.2 — The media endpoint [MVP] ✅ Done

_Implemented via `GetStoryHandoffMediaAction`, `StoryHandoffMedia` (9:16 crop through `PublishImageCropper`, single-range video on a seekable stream, a MIME allow-list). `max_share_mb` is 50._

**Goal.** Serve exactly the file Instagram should receive.

**Details.**

- The auto lane crops Story images to 9:16 around the focus point (`PublishImageCropper`, format
  `crop_to`). The handoff must serve the **same** crop, or what the client approved differs from
  what gets posted. Reuse the cropper; don't reimplement it.
- Stream through `AssetStorage`, same-origin, with the right `Content-Type`,
  `Content-Disposition: inline; filename="alima-story.jpg|mp4"`, and `Accept-Ranges` for video.
- One item only (the Story format is `max_items` 1). If the post has several assets, serve the one
  the adapter would send (`Post`'s "items that actually go out" helper, used by
  `UploadPostAdapter`).
- Token-gated by the same resolution as S-4.1. Do not issue a long-lived signed storage URL into
  the page.
- Videos can be large (≤100 MB by the registry). The page fetches the blob before the tap
  (S-0.1), so show progress and fall back to Save media if the fetch fails or the file is over a
  size you choose after S-0.1 testing. Record the number in config.

**Tests.** Image is served cropped to 9:16 (assert dimensions with a fixture); video streams with
the right type; wrong token gets nothing; an asset on a remote disk still streams.

**Done when.** The spike page from S-0.1, pointed at this endpoint, shares correctly.

### Step S-4.3 — The page [MVP] ✅ Done

_Implemented via `pages/story-handoff/show.tsx`, `components/story-handoff/*`, `lib/story-share.ts`. Its "Done when" (a real phone, under a minute) waits on S-0.1; no Playwright smoke test._

**Goal.** Three taps, one screen, usable one-handed.

**Files.** `resources/js/pages/story-handoff/show.tsx`, built on `public-shell.tsx` (the review
page's shell), small components under `resources/js/components/story-handoff/`.

**Layout (mobile first, one column).**

1. Eyebrow (DM Mono): the client name. Headline (Poppins SemiBold, sentence case, full stop):
   "Post this story."
2. A line that cannot be missed: "Post to **@clientname** on Instagram."
3. The media, 9:16, in a phone-shaped frame.
4. Primary button: **Share to Instagram**. Secondary: **Save media**.
5. "Then add these in Instagram" — the checklist from `StoryExtras::checklist()`. Each row: what
   it is, the value, and a **Copy** button. The link row is first.
6. The placement note, if any.
7. Primary button: **I've posted it**. Text link: "Something went wrong".

**Behaviour.**

- On mount, fetch the media as a blob and hold a `File`. Show a quiet progress state on the Share
  button until ready.
- Share tap, in one handler and in this order: `navigator.clipboard.writeText(link)` (if there is
  a link), POST `shared` (fire and forget, `keepalive`), then `navigator.share({ files: [file] })`.
- If `navigator.canShare?.({ files: [file] })` is false, the primary button is **Save media** and
  a three-line guide appears: "1. Save the media. 2. Open Instagram and start a story. 3. Pick it
  from your camera roll." Use the results of S-0.1 to decide wording per platform.
- After a share returns (or on return to the tab, `visibilitychange`), scroll to the checklist and
  highlight "I've posted it". Show a toast (sonner): "Link copied. Paste it into the link sticker."
- Each Copy button confirms with a toast and puts **that** value on the clipboard, so the poster
  can come back for the poll text after pasting the link.
- "I've posted it" → POST `posted`, then a done state: "Posted. Nice one." with the time. No
  confetti, no further actions.
- "Something went wrong" → a short text field and submit → `problem`.
- Late: a banner "This was due at 10:00." Expired: "The time to post this has passed. Ask your
  team to send a new reminder."
- Accessibility: buttons are real buttons, 44px targets, the checklist is a list, status is text
  plus colour.
- Brand: Cloud Cream workspace, one hero accent, semantic tokens only, no raw hex.

**Tests.** Vitest for the share handler order (mock `navigator.share`, `clipboard`), the
`canShare` fallback, and the state machine (ready → posted, ready → problem, expired). A Playwright
smoke test if the repo's Playwright setup covers public pages (`playwright.config.ts` exists).

**Done when.** On a real phone, from the notification to "Posted" takes under a minute for a
Story with one link.

### Step S-4.4 — Poster's queue [V1] ✅ Done

_Implemented via `stories.queue`, `GetStoryQueueAction`, `pages/stories/to-post.tsx`, the manifest shortcut, "Next story" on the done state._

**Goal.** Several Stories in one sitting.

An authenticated page, `stories/to-post` (name `stories.queue`), listing the signed-in member's
open handoffs that are due or due today, oldest first, each opening its handoff. On the handoff
page, when the visitor is signed in and has another due handoff, the done state offers "Next
story". Add it to the PWA manifest `shortcuts` ("Stories to post"). Team members only; the client
recipient flow stays single-link.

### Step S-4.5 — Opening a handoff from inside Alima [MVP] ✅ Done

_Implemented via `posts.story-handoff.open` behind `PostPolicy::postStoryByHand` (section 0, item 2), `story-handoff/not-ready.tsx`._

**Goal.** A member never needs the email.

- `GET posts/{post}/targets/{target}/story-handoff` (authenticated, `PostPolicy::update`): finds the
  open handoff, calls `IssueStoryHandoffLinkAction` and redirects to `story-handoff.show`. This is
  the URL the **bell** notification stores (S-3.1).
- If there is no open handoff (not due yet), render a small "Not due yet" state with the time.

**Tests.** Policy (Viewer and ClientReviewer are refused), redirect, and that issuing a new link
invalidates the previous token.

---

## Phase S-5 — Surfaces

### Step S-5.1 — Pills on calendar, lists and the editor [MVP] ✅ Done

_Implemented via `StoryChannelPresenter`, `StoryPostPresenter`, calendar / content / client posts / My work / portal calendar / editor._

Wherever a channel's state is shown (calendar occurrences from `BuildCalendarOccurrencesAction`,
content board, client posts tab, My work, the editor's channel list, portal calendar), add the
derived state for assisted channels:

| State                | When                                        | Label                   |
| -------------------- | ------------------------------------------- | ----------------------- |
| `needs_hand_posting` | `handoff_required`, post `scheduled`        | "Needs posting by hand" |
| `ready_to_post`      | open handoff, `due_at` passed               | "Ready to post"         |
| `posted_by_hand`     | channel `published`, has a `posted` handoff | "Posted by hand"        |

Compute it server-side in one place (a method on `PostTarget` or a small presenter) and pass it
through the existing payloads. Eager-load the open handoff to avoid N+1 on the calendar. Client
portal shows the first and third only.

### Step S-5.2 — Home / Needs attention and My work [MVP] ✅ Done

_Implemented via `GetNeedsAttentionItemsAction` (`storiesToPost`), `GetMyWorkAction`, the editor's "Open the story" / "Mark as posted" / "Send a new reminder"._

- `GetNeedsAttentionItemsAction`: a "Stories to post" group for open, due handoffs the viewer is
  the poster for (or all of them for Owner/Strategist), each linking to S-4.5. Missed handoffs
  already surface as failed publishes; make sure the reason text is the `StoryNotPosted` one.
- `GetMyWorkAction`: include the viewer's assisted Stories due today.
- Editor, on a failed assisted channel: "Send a new reminder" (the existing retry) and, for
  Owner/Strategist/Designer, "Mark as posted" (see the late-confirmation note in S-2.4).

### Step S-5.3 — Reporting honesty [V1] ✅ Done

_Implemented via `GetPublishReliabilityStatsAction`, `EngagementMetrics`, the client report, the super-admin Publishing panel._

- `GetPublishReliabilityStatsAction`: count missed handoffs separately from vendor failures, so an
  agency's "publish reliability" isn't dragged down by a teammate who forgot.
- Analytics and client reports: an assisted Story has no platform post id, so show "not available"
  (the existing `post_metrics`-off wording) instead of zeros.
- Add `opened_at → completed_at` median to the super-admin publishing view if one exists; it is
  the number that says whether the lane works.

### Step S-5.4 — Public API and MCP [V1] ✅ Done

_Implemented via `App\Support\Api\StoryChannels`, the API post endpoints, the four MCP tools, `openapi.json`._

Find the post resource used by the public API and the MCP tools (`create_draft_post`,
`update_post`, `get_post`, `schedule_post`). Accept and return `story_extras` and
`handoff_recipient` on a channel, validate with the same rules as S-1.3, and document that a
channel with extras is posted by hand. `schedule_post` over MCP must return a clear note when a
channel is assisted, so an agent doesn't tell a user it will publish itself. Update `api.md`,
`mcp.md` and `.junie/mcp-security-review.md` if it lists tool inputs.

### Step S-5.5 — Outgoing webhooks [Stretch] ✅ Done

_Implemented via `WebhookEvent::StoryReadyToPost`, dispatched by `NotifyStoryPosterAction` on the first notification._

`WebhookEvent` already has per-post events (`post.published`, `post.failed`). Add
`story.ready_to_post` so an agency can route the reminder into its own tools.

---

## Phase S-6 — Stretch and hardening

### Step S-6.1 — Auto-confirm through Meta Graph [Stretch] — ⏸ Not built (see section 0)

**Goal.** Remove the "I've posted it" tap and get a real media id.

Alima already holds a Meta grant for connected Instagram accounts (`meta_access_token`,
`meta_instagram_user_id`, `MetaTokenService`, used by `MetaGraphMusicProvider`). Meta's API lists
an account's currently live Stories. While a handoff is open and `shared_at` is set, poll that
list every minute or two for a Story created after `shared_at`; on a match, complete the handoff
(`completed_via` auto) and store the id as `platform_post_id`.

**Before building, verify:** which permission the stories edge needs and whether the app's current
scopes (`instagram_basic`, `instagram_content_publish`) cover it; rate limits; and whether an org
without a Meta grant (Upload-Post-only connect) simply keeps the manual tap. This step is a
maybe. Do not promise it in the UI until it works.

### Step S-6.2 — "If nobody posts, publish it plain" [V1] ✅ Done

_Implemented via `ExpireStoryHandoffAction` + `PublishPostTargetJob($plain)`, the editor checkbox._

Honour `story_extras.if_missed = publish_plain`. In `ExpireStoryHandoffAction`, instead of failing
the channel: set `outcome = published_plain`, clear `handoff_required` **for this attempt only**
(a transient flag on the dispatch, not the stored column), and send the channel through the vendor
batch. Guards:

- Lock the handoff first, so a confirm arriving at the same moment wins and nothing is sent.
- The editor option reads: "If no one posts it within 2 hours, publish it without the link." Off by
  default, per post.
- Activity log says it went out plain and why.
- Double-post risk: if the poster posted but never confirmed, the plain version also goes out. Say
  so next to the option. S-6.1 removes most of that risk; consider shipping this only after it.

### Step S-6.3 — Facebook Stories [Stretch] — ⏸ Not built (see section 0)

The `story` format exists for Facebook too. Extend the extras panel and the handoff page ("Post to
**Page name** on Facebook") once Instagram has proven the lane. Check which stickers Facebook
offers; the list differs.

### Step S-6.4 — Hardening, docs and exit [MVP] ✅ Done

_Implemented via the security pass, export / purge, `SecretScrubber::scrubUrl()`, `ops.md`, `publishing.md`, `docs/why-stories-with-links-are-posted-by-hand.md`. The flag was **not** flipped (section 0, item 1)._

- Security pass with the `security-and-hardening` skill on the token routes: enumeration,
  throttling, payload scope, no token in logs, in the `notifications` table, in error reports or in
  `Referer` (add `Referrer-Policy: no-referrer` on the page).
- Data export and org purge (`BuildOrganizationDataExportJob`, `PurgeOrganizationJob`) include and
  delete `story_handoffs`.
- `ops.md`: the kill switch, the sweep, how to read a stuck handoff, what the three outcomes mean.
- `publishing.md`: a "Stories with extras (assisted lane)" section covering D1 to D8 as built.
- Help copy for agencies: one short page, "Why stories with links are posted by hand".
- Flip `STORY_HANDOFF_ENABLED` default to `true`.

---

## Suggested order and MVP cut

1. **S-0.1** on real phones. Stop here if it's a no-go.
2. **S-1.1 → S-1.5** and **S-2.1 → S-2.5** together, flag off.
3. **S-3.1, S-3.2**, then **S-4.1 → S-4.3, S-4.5**.
4. **S-5.1, S-5.2**, **S-6.4**. Flag on for Alex's own agency first.
5. V1: S-3.3, S-3.4, S-3.5, S-4.4, S-5.3, S-5.4, S-6.2.
6. Stretch: S-6.1, S-5.5, S-6.3.

MVP is every step tagged **[MVP]**: 20 steps.

## Exit criteria

- A plain Story still auto-publishes, byte for byte as before (regression tests green).
- A Story with a link, scheduled for a set time, produces a push, an email and a bell item to the
  right person within a minute of that time, and never reaches Upload-Post.
- From the notification, a poster on iPhone and on Android gets the media into Instagram's Story
  editor with the link on the clipboard, and can confirm.
- An unconfirmed Story fails with a plain reason, shows in Needs attention, and can be retried.
- A ClientReviewer sees extras only on their own client's posts and no handoff for another client.
- No token appears in any log line, stored notification or error report.
- PHPStan level 7, Pint, PHPUnit, `vp check` and `tsc --noEmit` pass (`composer ci:check`).
- Module docs are current.

## Open questions for Alex

1. **Window length.** 120 minutes from due time before a Story counts as missed. Right for your
   clients, or should it be per client?
2. **Who is "the team"?** The plan uses the post's assignee, then its creator, then everyone. Is
   there a better owner, such as the client's account owner?
3. **Client-posted Stories.** Do any clients actually want to post their own, or is `team` the only
   real case for now? If so, the `client` recipient can drop to V1 and save work in S-3.1 and S-1.5.
4. **Email for agencies.** The plan emails the poster at due time as well as push and bell. Too
   noisy once push works?
