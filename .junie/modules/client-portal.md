# Client Portal

## Purpose
Phase 6: lets an org (Owner/Strategist) invite a named contact at one of its clients to a real,
authenticated portal — distinct from the token-based, single-post `/review/:token` link (Step
0.13). Step 6.1 covers the invitation itself (model, send, revoke); Step 6.2 covers accepting the
invitation and creating the login. Step 6.3 adds the portal shell/navigation and the
`EnsureClientPortalAccess` scoping middleware. Step 6.4 adds the real portal dashboard
(needs-attention content scoped to the contact's own client). Step 6.5 adds the portal's posts
list and approve/request-changes/comment flow. Step 6.6 adds the portal's read-only invoices
list/detail, completing Phase 6.

## Models / Tables
- `ClientInvitation` (`client_invitations`): `client_id` (FK), `email`, `invited_by_user_id` (FK to
  `users`), `token_hash` (sha256 of the plain token — hashed at rest per spec §10, the plain value
  is only ever available via the signed accept-invite URL built in
  `CreateClientInvitationAction`), `expires_at`, `accepted_at` (nullable — set by Step 6.2),
  `revoked_at` (nullable). `belongsTo` `Client` and `invitedBy` (`User`). `isPending(): bool`
  reports whether the invitation is still usable (unaccepted, unrevoked, unexpired) — used both to
  render the "pending" badge and (in Step 6.2) to validate the accept-invite token, the same way
  `ReviewToken::isValid()` is used by the review portal.
- `Client::invitations()`: `hasMany` `ClientInvitation`.
- `User`: `role = Role::ClientReviewer` + `client_id` set by `AcceptClientInvitationAction` (Step
  6.2) — reused if a `Role::ClientReviewer` user already exists for the invited email, otherwise
  created fresh.

## Actions / Services / Contracts
- `App\Actions\Portal\GetPortalDashboardItemsAction` (Step 6.4): given the authenticated
  `Role::ClientReviewer`, returns `waitingApprovals`/`changesRequested`/`upcomingPosts` — mirrors
  the shape of `App\Actions\Home\GetNeedsAttentionItemsAction` but scoped to
  `auth()->user()->client_id` and deliberately narrower (no failed-publish/disconnected-account
  internals, which stay Owner/Strategist-only per §3).
- Step 6.5 introduces no new business-logic actions: `PortalPostController::decide`/`comment`
  reuse the existing `App\Actions\Posts\TransitionPostStatusAction` and
  `App\Actions\Posts\CreatePostCommentAction` (the same ones the internal editor and the
  `/review/:token` portal use), gated by the same `PostPolicy::transition`/`comment` rules — just a
  new, authenticated entry point.
- `App\Actions\ClientInvitations\CreateClientInvitationAction`: wrapped in `DB::transaction()`,
  creates the invitation (random 64-char plain token, sha256-hashed at rest, 7-day expiry) and
  emails `App\Mail\ClientInvitationMail` (Resend) with a signed `/client-invite/{token}` link.
  Logs the send (`org_id`, `client_id`, invited email — never the raw token) at `info`; any mail
  failure is caught and logged at `warning` rather than bubbling up, so a transient mail outage
  doesn't roll back the invitation record itself.
- `App\Policies\ClientInvitationPolicy`: `viewAny`/`create(user, client)` and `delete(user,
  invitation)` — Owner/Strategist only, scoped to the invitation's client's `org_id`. Registered
  via `Gate::policy` in `AppServiceProvider`.
- `App\Actions\ClientInvitations\AcceptClientInvitationAction` (Step 6.2): wrapped in
  `DB::transaction()`, creates (or reuses, by email + `Role::ClientReviewer`) a `User` scoped to
  the invitation's `client_id`/`org_id`, sets the chosen name/password, marks the invitation's
  `accepted_at`, and logs the acceptance (`org_id`, `client_id`, `user_id`) at `info`.

## Middleware
- `App\Http\Middleware\EnsureClientPortalAccess` (Step 6.3, aliased as `portal`): applied to every
  `/portal/*` route. Aborts 403 unless the authenticated user is a `Role::ClientReviewer` with a
  `client_id`. Generically inspects any `{post}`/`{invoice}`/`{campaign}` route-model binding and
  aborts 404 if its `client_id` doesn't match `auth()->user()->client_id` — never a value taken
  from the request — so `/portal/*` routes inherit the isolation check automatically just by
  binding those parameters.

## Phase M-6 additions (Step M-6.6 — mobile-first PWA)
- The portal is an installable PWA: `public/portal.webmanifest` (scope `/portal`, start_url
  `/portal`) + `<PwaProvider manifestHref="/portal.webmanifest">` mounted in
  `client-portal-layout.tsx` (the shared `PwaProvider` now takes `manifestHref`/`title`/`description`;
  the existing business install is unchanged). The service worker (`public/sw.js`, scope `/`) is
  reused. The portal shell is already mobile-first (responsive header/nav, `max-w-6xl`, `sm:`
  breakpoints).
- **Swipe to approve** (`components/portal/swipe-to-approve.tsx`): on the portal post page, drag the
  thumb right to approve / left to request changes — additive to the tap buttons. The gesture→decision
  mapping is the pure `decideFromOffset`, unit-tested in `swipe-to-approve.test.tsx`.
- E2E: the `mobile` Playwright project (Pixel 5) runs `tests/e2e/portal-approve-mobile.spec.ts` — an
  agency sends a post to the client, invites a contact, who accepts (auto-signed into the portal,
  M-6.2) and approves on a phone viewport. Runs in CI (the e2e server is CI-hosted).

## Phase M-6 additions (Step M-6.5 — overdue approvals)
- The portal dashboard (`GetPortalDashboardItemsAction`) marks a reviewer's own approvals that are
  past the agency's `approval_sla_hours` with an `overdue` flag; `portal/dashboard.tsx` shows an
  "Overdue" badge. The daily `SendOverdueApprovalRemindersJob` emails the client's review-email
  contacts a reminder (fresh review link) for a `waiting_client` post. See `settings.md` / `home.md`
  / `notifications.md`.

## Phase M-6 additions (Step M-6.3 — multi-approver & staged approvals)
- `clients.approval_policy` (`ApprovalPolicy`: `any` / `all` / `sequential`, default `any` =
  original single-approver behaviour; migration `2026_11_05_100100`, edit-form field). `approval_steps`
  (migration `2026_11_05_100200`): one required approval per post (`client_contact_id` nullable =
  generic step for a client with no named approvers), `position`, `decision`, `decided_by_user_id`,
  `decided_at`, `comment`, `approved_revision_id`.
- `BuildApprovalStepsAction` (re)builds the steps from the client's approver contacts whenever a post
  enters `waiting_client` (hooked in `TransitionPostStatusAction`). `SubmitClientDecisionAction` is
  the **single entry point** for a client decision — the portal (`PortalPostController::decide`), the
  review-link token (`ReviewController::decide`) and campaign batch review
  (`BatchReviewCampaignPostsAction`, C-5.2) all go through it. It records the acting approver's step
  and only releases the post to `approved` once the policy is satisfied (any = one; all = everyone;
  sequential = in order, enforced). "Changes requested" from any approver stops the post. The
  anonymous token, and a client with no named approvers, take the generic whole-client path.
- `ApprovalProgress` presents the step list and answers "is it this reviewer's turn?" so the portal
  only shows Approve / Request-changes to the right approver. The portal post page shows the approval
  progress strip; the posts list has **Approve all** (`portal.posts.decide-all`).
- **Approval certificate.** `GenerateApprovalCertificatePdfAction` + `pdf.approval-certificate`
  (Studio Paper branding): who approved, when, under which policy, and the SHA-256 content hash of
  the approved revision (`PostRevision::contentHash()`). Routes: `portal.posts.certificate` (client)
  and `posts.approval-certificate` (staff), both approved-only and `throttle:exports`.
- Tests: `tests/Feature/Approvals/ApprovalStepsTest` (all three modes end-to-end, out-of-turn
  rejection, non-approver rejection, generic/token path, certificate PDF + route, approve-all).

## Phase M-6 additions (Step M-6.2 — one passwordless client experience)
- **Magic link.** `portal_login_tokens` (migration `2026_11_04_100300`): `user_id`, `token_hash`
  (SHA-256, unique), nullable `post_id` (landing target), `expires_at`, `used_at`. `PortalLoginToken`
  + `CreatePortalLoginTokenAction` (hashed at rest, plain token returned once, 7-day TTL).
  `GET /portal-login/{token}` → `Portal\MagicLinkController@login` (public, `throttle:public-token`):
  a valid, unexpired, unused token signs the reviewer in (session regenerated against fixation),
  consumes the token (single-use), and lands on the emailed post when it's still client-visible,
  else the portal dashboard. An invalid/used/expired token falls back to `login` without leaking why.
- **Review emails prefer the magic link.** `SendClientReviewRequestedEmailAction` now sends each
  review-email contact (Step M-6.1) a personal magic link when they already have a portal login
  (`Role::ClientReviewer` for that client), and the shared token-only `/review/:token` link otherwise.
- **Invite accept signs straight in.** `ClientInvitationAcceptController@store` logs the new/updated
  reviewer in and redirects to `portal.dashboard` (was: a bounce to `/login`).
- **Reviewers are fully blocked from the internal app.** `portal.redirect`
  (`RedirectClientReviewersToPortal`) is now applied to the whole internal `org.member` workspace
  group, so every internal route sends a reviewer to their `/portal/*` equivalent (or the portal
  dashboard). Post-scoped routes (`posts.edit`, `posts.transition`, `posts.comments.store`) land them
  on that post in the portal. The notification bell, profile and logout stay in the shared
  `auth`/`verified` group and remain reachable. Generated guard:
  `tests/Feature/Security/ClientReviewerInternalRedirectTest`. Reviewers approve/comment only via
  `portal.posts.decide` / `portal.posts.comments.store`.
- Tests: `tests/Feature/Portal/MagicLinkTest` (sign-in + landing, single-use, expiry, hashed at rest,
  magic-vs-review-link selection) and the updated `ClientInvitationAcceptControllerTest`.

## Phase M-3 additions (Step M-3.3)
- **Reports:** `GET /portal/reports` and `/portal/reports/{report}` (+ `/pdf`) →
  `Portal\PortalReportController` — the contact's own client's **published** monthly reports
  only; `EnsureClientPortalAccess` 404s another client's report or a draft. "Reports" sits in the
  portal nav. The page reuses `ClientReportDocument` (with a "waiting for your approval" link to
  Posts). Reports are emailed to portal reviewers (plus the approval email) when published.
- Campaign wrap reports show the frozen engagement block when the share captured one.

## Phase M-2 additions

- Dashboard "Connect your accounts" panel (`connectAccounts` prop from `ConnectSlots::forList()` —
  platform, handle, connected only) for `ClientReviewer`s, connecting their own client's accounts
  through the vendor popup (M-2.8). Post and campaign review previews show each channel's
  published caption and first comment.

## Routes / Controllers
- `POST /clients/{client}/invitations` → `ClientInvitationController::store` — validated via
  `StoreClientInvitationRequest` (email required + a custom `withValidator` rule rejecting a
  duplicate *pending* invitation to the same email/client), delegates to
  `CreateClientInvitationAction`, redirects to `clients.show`.
- `DELETE /invitations/{invitation}` → `ClientInvitationController::destroy` — sets `revoked_at`,
  redirects to `clients.show`. No dedicated list endpoint: pending/accepted invitations are loaded
  by `ClientController::show` (via `transformInvitation`, which never includes the token/hash) and
  rendered inline, the same way invoices/social accounts are.
- Both routes are mirrored under the `preview.*` prefix (write-blocked by the `preview` middleware,
  same pattern as every other write route).
- `GET /client-invite/{token}` and `POST /client-invite/{token}` →
  `ClientInvitationAcceptController::show`/`store` (Step 6.2) — unauthenticated, mirroring the
  `/review/{token}` routes: `show` renders the accept-invitation page (client name + invited
  email), `store` validates via `AcceptClientInvitationRequest` (name + `passwordRules()`),
  delegates to `AcceptClientInvitationAction`, then redirects to `login` with a success status.
  Both resolve the invitation the same way `ReviewController` resolves a `ReviewToken` (sha256
  hash lookup + `isPending()` check), throwing a 404 on any mismatch/expired/revoked/already-
  accepted token — never a validation error, so token guessing/enumeration gives no signal.
- `GET /portal` → `PortalDashboardController::index` (`portal.dashboard`) — behind
  `auth`/`verified`/`portal` middleware, renders the portal dashboard via
  `GetPortalDashboardItemsAction` (Step 6.4).
- `GET /portal/posts` → `PortalPostController::index` (`portal.posts.index`, Step 6.5) — lists
  every post visible to the contact's own `client_id` (pre-review internal stages — idea, draft,
  internal review — are excluded server-side), optionally filtered by `?status=`.
- `GET /portal/posts/{post}` → `PortalPostController::show` (`portal.posts.show`) — the
  authenticated counterpart to `ReviewController::show`: full post detail (caption, hashtags,
  assets, targets, non-internal comments only) plus a `can.decide`/`can.comment` payload driving
  which forms render. **UX overhaul (review handoff):** also returns a `queue` payload
  (`{remaining, next_id}`) — how many *other* posts still await this reviewer (same eligibility as
  `decideAll`: waiting, their turn, a legal approve) and the next one to open — so the page shows a
  one-tap "Review the next" card ("approving always leads", brand §8). `decide` still redirects back
  to `portal.posts.show` (unchanged).
- `POST /portal/posts/{post}/decide` → `PortalPostController::decide` (`portal.posts.decide`,
  Step 6.5) — validated by `App\Http\Requests\Portal\PortalPostDecisionRequest` (mirrors
  `TransitionPostRequest`'s authorization via `PostPolicy::transition`), calls
  `TransitionPostStatusAction`, redirects back to `portal.posts.show`. No new transition logic:
  this is the authenticated counterpart to `ReviewController::decide`.
- `POST /portal/posts/{post}/comments` → `PortalPostController::comment`
  (`portal.posts.comments.store`, Step 6.5) — reuses `StorePostCommentRequest`/
  `CreatePostCommentAction` (which already forces `internal_only = false` for
  `Role::ClientReviewer`), redirects back to `portal.posts.show`.
- `GET /portal/invoices` → `PortalInvoiceController::index` (`portal.invoices.index`, Step 6.6) —
  lists every invoice for the contact's own `client_id`, newest `issue_date` first; no
  create/edit/send/mark-paid actions (per §3's "no billing visibility beyond their own invoices"
  boundary for `Role::ClientReviewer`).
- `GET /portal/invoices/{invoice}` → `PortalInvoiceController::show` (`portal.invoices.show`,
  Step 6.6) — read-only detail for a single invoice, scoped by `EnsureClientPortalAccess`.
- `GET /portal/company` → `PortalClientController::show` (`portal.company`, Step 6.7) — the
  contact's own `Client` record (name, legal name, website, industry, countries, status, default
  language, start date). No billing-sensitive fields (retainer, billing cycle) are included,
  mirroring `ClientPolicy::viewBilling`'s boundary for `Role::ClientReviewer`.
- `GET /portal/campaigns` → `Portal\PortalCampaignController::index` (`portal.campaigns.index`) —
  every campaign for the contact's own `client_id`, each row carrying a `posts_count` scoped to
  the same visible-status allowlist as `PortalPostController`.
- `GET /portal/campaigns/{campaign}` → `Portal\PortalCampaignController::show`
  (`portal.campaigns.show`) — campaign detail plus its visible-status posts, each linking into
  `portal.posts.show`. See `.junie/modules/campaigns.md` for the full feature note (this is
  primarily a Campaigns/Assets-module feature, cross-linked here since it lives under `/portal`).

## Frontend
- `resources/js/layouts/client-portal/client-portal-layout.tsx` (Step 6.3): the portal's distinct
  shell — no internal-ops chrome (no "Needs attention"/Settings/other clients), just a branded
  header (client name + Graphy mark), a top nav (Posts & approvals, Invoices), and a log-out
  action. Mirrors `/review/:token`'s lighter, simplified brand variant (§7 of the brand
  guidelines) rather than the dark-sidebar `AppSidebarLayout`.
- `resources/js/pages/portal/dashboard.tsx` (Step 6.4): opens with the Ink `ChapterHero` ("Welcome
  back, Rae." — the first name as the Lime accent word), whose `spark` "Start reviewing" CTA opens
  the first waiting post and whose hero figure is the `waitingApprovals` count as a giant Lime
  numeral. Below it, a "Ready for review" `Panel` (first four waiting posts, "All N" link when
  there are more) sits beside "Going out", then "Changes you've requested". Rows link into
  `portal.posts.show`. Portal pages render no chapter running head (`ShellPaddedContext`) — the
  numbered chapters belong to the studio.
- `resources/js/pages/portal/posts/index.tsx` (Step 6.5): the posts list, with status-filter chips
  (`?status=`) and a card/badge row per post linking into `portal.posts.show` (same pattern as the
  dashboard sections).
- `resources/js/pages/portal/posts/show.tsx` (Step 6.5): the authenticated counterpart to
  `review/show.tsx` — review message/target chips, non-internal comments, an
  approve/request-changes `Form` (visible when `can.decide`) posting to `portal.posts.decide`, and
  a separate comment `Form` (visible when `can.comment`) posting to `portal.posts.comments.store`.
  The plain caption/hashtags/asset-grid block was replaced with the same `SocialPostPreview`
  Planable-style live preview used read-only in `posts/edit.tsx` (see the 2026-09-22 changelog
  entry below) — a pill switcher lets the client flip between target accounts when a post has
  more than one.
- `resources/js/pages/portal/invoices/index.tsx` (Step 6.6): the invoices list — a card row per
  invoice (number, issue/due dates, amount, and a status badge that reads "Unpaid" for any
  non-`paid` status, else the normal status label), linking to `portal.invoices.show` (same
  card/badge pattern as the posts list).
- `resources/js/pages/portal/invoices/show.tsx` (Step 6.6): read-only invoice detail (amount,
  issue/due dates, the same "Unpaid"-or-status badge), linked from the invoices list; now also
  renders the organization-uploaded invoice PDF inline (`<iframe>`) when one exists (see
  `.junie/modules/billing.md`'s 2026-09-19 changelog entry) — no upload/edit controls here.
- `resources/js/pages/clients/show.tsx` gained a "Portal access" tab (`UserPlus` icon) alongside
  the existing Social accounts/Campaigns/Posts/Assets/Billing tabs, following the exact same
  inline-form-plus-list pattern as the Billing history tab: an email-only form (visible when
  `can.createInvitation`) and a list of invitations showing email, a status badge
  (pending/accepted/revoked/expired, derived client-side from `accepted_at`/`revoked_at`/
  `is_pending`), expiry date, and a "Revoke" button (visible when `invitation.can.delete`). The
  stat-card row gained a matching "Portal access" card (count of invitations with a non-null
  `accepted_at` and no `revoked_at` — i.e. active portal logins, not merely pending invites) and
  the grid was widened from 5 to 6 columns.
- `resources/js/pages/client-invite/show.tsx` (Step 6.2): an unauthenticated, branded accept-
  invitation page mirroring `review/show.tsx`'s layout (logo header, card, footer) with a
  name/password/confirm-password form posting to `/client-invite/{token}`; on success the browser
  is redirected to `login` with a flash status.
- `resources/js/pages/portal/company.tsx` (Step 6.7): a read-only card showing the contact's own
  company fields, linked from a new "Company" item in the portal nav
  (`client-portal-layout.tsx`).
- `resources/js/pages/portal/campaigns/index.tsx` / `campaigns/show.tsx`: the campaigns list/detail
  (mirroring `portal/posts/index.tsx`/`show.tsx`), linked from a new "Campaigns" item in
  `client-portal-layout.tsx`'s nav — see `.junie/modules/campaigns.md` for the full note.
- `App\Http\Middleware\HandleInertiaRequests::share()` now also shares a `client: {name}` prop
  whenever the authenticated user is a `Role::ClientReviewer` (previously each portal controller
  had to remember to pass it individually), so the portal header always shows the client name.
- `resources/js/components/app-sidebar.tsx` now renders a different nav list for
  `auth.user.role === 'client_reviewer'` (Home, Calendar, "Client portal" link into `/portal`)
  instead of the org-internal `mainNavItems`, which included "Clients" and "Needs attention" —
  both of which 403 for that role (`ClientPolicy::viewAny`/`PostPolicy::viewHome` exclude
  `Role::ClientReviewer`).
- `resources/js/pages/calendar/index.tsx`: occurrence chips now link a `client_reviewer` viewer to
  `portal.posts.show` instead of the internal `posts.edit` editor, so clicking a post from the
  calendar lands them on the page that actually has the approve/request-changes UI.
- `resources/js/pages/posts/edit.tsx`: the "Editor" tab previously rendered nothing at all when
  `can.update` was false (any role other than Owner/Strategist/Designer, including
  `Role::ClientReviewer` and `Role::Viewer`) — it now renders a read-only view of the caption,
  hashtags, target accounts, music/location, and media in that case.

## Business Rules
- Client reviewers never see internal UI: every internal route they could reach redirects into the
  portal (`RedirectClientReviewersToPortal`), and `AppLayout` renders the portal shell for them.
- Every portal surface filters posts through `PostStatus::clientVisible()`; a bound internal-status
  post 404s in `EnsureClientPortalAccess`.
- Only Owner/Strategist can send or revoke invitations, scoped to their own org's clients (mirrors
  `SocialAccountPolicy`).
- A client can't have two simultaneously-pending invitations to the same email — `revoked_at`/
  `expires_at` on the prior one must be set (naturally or by an explicit revoke) before a new one
  can be sent to that address.
- The plain invitation token is never persisted (only its sha256 hash) and never appears in any
  Inertia page's JSON props — `ClientController::transformInvitation` only exposes
  id/email/timestamps/`is_pending`/`can`.

## Tests
- `tests/Feature/PortalInvoiceControllerTest.php` (Step 6.6): a `Role::ClientReviewer` sees only
  their own client's invoices on `/portal/invoices` and never another client's; unpaid (draft/sent)
  invoices are flagged `is_unpaid: true` and paid ones aren't; the contact can view their own
  invoice detail; and viewing another client's invoice id directly 404s.
- `tests/Feature/PortalPostControllerTest.php` (Step 6.5): a `Role::ClientReviewer` sees their own
  visible-status posts (not pre-review internal stages) on `/portal/posts` and never another
  client's; can approve/request-changes on their own `waiting_client` post (reusing
  `TransitionPostStatusAction`); is rejected (404/403) deciding on another client's post or a post
  that isn't `waiting_client`; can comment on their own post with the comment always forced
  non-internal; and the detail page never exposes `internal_only` comments.
- `tests/Feature/PortalDashboardTest.php` (Step 6.4): a `Role::ClientReviewer` sees their own
  waiting-approval/changes-requested/upcoming-scheduled posts on `/portal`, and never sees another
  client's posts.
- `tests/Feature/ClientPortalAccessTest.php` (Step 6.3): a `Role::ClientReviewer` can view the
  portal dashboard and their own client's post/invoice; every other role gets a 403 on
  `/portal/*`; a `Role::ClientReviewer` guessing another client's post or invoice id gets a 404;
  guests are redirected to `login`.
- `tests/Feature/ClientInvitationControllerTest.php`: sending an invitation (+ mail sent), the
  duplicate-pending rejection, sending again after a revoke, Designer/cross-org rejection on both
  send and revoke, revoking a pending invitation, and a secret-leak-style assertion that the token
  hash never appears in the rendered `clients/show` page (mirrors the Step 0.15 pattern).
- `tests/Feature/ClientInvitationAcceptControllerTest.php` (Step 6.2): a fresh accept (new
  `Role::ClientReviewer` user created, scoped to the right `client_id`/`org_id`, invitation marked
  accepted), reusing an existing `Role::ClientReviewer` user for the same email, a 404 on an
  already-accepted/expired/revoked/invalid token (on both `show` and `store`), and the new user
  having no cross-client `client_id` (mirrors the Step 0.13 isolation-test pattern).

- `tests/Feature/PortalClientControllerTest.php` (Step 6.7): a `Role::ClientReviewer` can view
  their own company record on `/portal/company` and it never includes billing fields
  (`retainer_amount`/`billing_cycle`); a reviewer with no `client_id` gets a 403; a non-reviewer
  gets a 403.
- `tests/Feature/PortalCampaignControllerTest.php`: a `Role::ClientReviewer` sees only their own
  client's campaigns (with a visible-status posts count) on `/portal/campaigns`; can view their
  own campaign's detail page (visible-status posts only); gets a 404 for another client's
  campaign id; a non-reviewer is forbidden. See `.junie/modules/campaigns.md` for the full
  feature note.

## Step M-4.2 — "Mark Paid" payment handshake
- On a read-only portal invoice that's still `sent`, a client reviewer gets a **"Mark Paid"** button
  (`portal/invoices/show.tsx`) opening a small dialog (optional method / reference / note) that posts
  to `portal.invoices.report-payment` (`PortalInvoiceController::reportPayment`, scoped to their own
  `client_id`; drafts 404). It moves the invoice `sent → pending_confirmation` and notifies the
  studio — it never marks the invoice paid. Once reported, the portal shows a "Payment reported —
  awaiting confirmation" banner. The org confirms or reverts from the internal invoice page. See
  `billing.md` › Phase M-4 for the full state machine and actions.

## Changelog (attachment previews)
- 2026-09-22: Replaced the plain caption/hashtags/asset-grid block on `/portal/posts/{post}` with
  the same live preview (`resources/js/components/social-post-preview.tsx`'s `SocialPostPreview`)
  used read-only in `posts/edit.tsx`'s "Live preview" card, so the client portal now shows the
  actual Instagram/TikTok/feed-style mockup instead of a bare list. `PortalPostController::show`
  now also sends `music`/`location` (already stored on `Post`, previously unused here) and each
  target's `social_account_id` (used as the preview's stable target-switcher id, matching the
  editor's `previewTargets` pattern) alongside the existing caption/hashtags/assets fields. The
  target chips (handle + scheduled date/time) are kept above the preview since they carry
  scheduling info the preview itself doesn't show. No backend business logic changed — purely a
  read-only rendering swap plus two extra already-stored fields in the existing payload.
- 2026-09-19: Fixed broken attachment previews on `/portal/posts/{post}` (and the equally-affected
  `/review/:token` page): both pages were checking `asset.type === 'upload'` to decide whether to
  render an `<img>`, but `type` is the `AssetType` enum (`image`/`video`/`document`/`other`) while
  `upload` is a value of the separate `AssetSource` enum (`upload`/`figma`/`url`) — so every asset
  fell through to the plain filename-only `<div>`. Added `source` and `mime_type` to the asset
  payload in `PortalPostController::show` and `ReviewController::show`, and introduced a shared
  `resources/js/components/asset-preview.tsx` (`AssetPreview`) used by both `portal/posts/show.tsx`
  and `review/show.tsx`: renders an `<img>`/`<video>` for uploaded image/video files (by
  `mime_type`/`type`), an image or an external-link card for `source: 'url'` (image detected by
  file extension), and a Figma-branded external-link card for `source: 'figma'`, all opening in a
  new tab when not directly embeddable.

## Known Gaps / Open Questions
- No login redirect target exists yet beyond the generic `login` page; the post-accept redirect in
  `AcceptClientInvitationAction`/`ClientInvitationAcceptController` could now point straight at
  `/portal` since the shell exists, but this hasn't been changed.
- `Role::ClientReviewer` can still open `/dashboard`/`/calendar` directly (existing tests rely on
  this), so the internal app and the portal remain two parallel surfaces for that role rather than
  a single redirect-on-login flow — the sidebar/calendar fixes below route them to the portal
  wherever they'd otherwise hit a dead end, but a determined user can still navigate the internal
  app's read-only bits.

## Changelog
- 2026-10-24: UX overhaul (review handoff, `.junie/plans/ux-overhaul.md`) — the portal post page
  now carries a one-tap review queue. `PortalPostController::show` returns `queue {remaining,
  next_id}` (other posts still awaiting this reviewer); `portal/posts/show.tsx` shows a "Review the
  next" card so "approving always leads" (brand §8). Tests in `PortalPostControllerTest` (queue
  counts/excludes correctly; empty when nothing else waits). Pairs with the studio-side copyable
  review link (`posts.md`).
- 2026-10-21: Step M-3.3 — portal Reports (own client, published only, PDF); wrap reports show their engagement snapshot (M-3.1).
- 2026-10-20: Phase 18 (grid planner) — portal grids: `GET /portal/grids`, `GET /portal/grids/{grid}`, `POST …/decide` (approve / request changes with flagged tiles, stale-version check), `POST …/comments` (`PortalGridController`). `EnsureClientPortalAccess` 404s another client's grid and any grid not yet sent (`GridPlanStatus::clientVisible()`). The shared `portal` prop gains `grids: {visible, waiting}` (cached, `SharedPropsCache::portalGrids()`), so the nav shows "Grids" only once a grid has been sent, with a waiting badge; the dashboard lists "Grid waiting on you" (`waitingGrids`). Review links can carry a grid (`review/grid` page, `POST /review/{token}/grid`). Client payloads never include team notes, internal comments, Image Studio data or billing — `GridClientReviewTest`.
- 2026-10-20: Phase M-2 — connect panel, per-channel previews.
- 2026-10-18: Fixed a second "Attempted to lazy load [client] on model [ClientInvitation]" spot:
  `ClientInvitationPolicy::delete` read `$invitation->client->org_id` directly, which throws
  whenever the invitation wasn't eager-loaded with `client` — notably on `GET
  clients/{client}/portal-access` (`ClientTabController::portalAccess`), where
  `ClientTabPresenter::invitation()` calls `$user->can('delete', $invitation)` for every row in
  the paginated list. The policy now calls `$invitation->loadMissing('client')->client->org_id`
  (an explicit load, not a bare lazy access), so it's safe regardless of what the caller
  eager-loaded; `ClientTabController::portalAccess` also now eager-loads `client:id,org_id` on the
  invitations query to avoid an N+1 across the page.
- 2026-10-18: Fixed "Attempted to lazy load [client] on model [ClientInvitation]" thrown when
  sending a portal invite: `CreateClientInvitationAction` now calls
  `$invitation->setRelation('client', $client)` right after `ClientInvitation::create()`, since
  `ClientInvitationMail::build()` reads `$this->invitation->client->name` for the subject line and
  lazy loading is disabled app-wide. Added `tests/Feature/ClientInvitationMailTest.php`, which
  renders the mailable directly (not via `Mail::fake()`, which never builds/renders it and so
  couldn't have caught this) as a regression test.
- 2026-10-18: Campaigns enhancement C-5.4 — public, read-only campaign preview links (`/share/campaigns/{token}`, `share/campaign`) for stakeholders without portal access: hashed, expiring, revocable, Approved-onward posts only. See `campaigns.md`.
- 2026-10-18: Campaigns enhancement C-5 — portal campaign page v2 (`portal/campaigns/show`: brief sign-off, phases, posts, conversation, shared report), batch review (`portal.campaigns.review` / `.review.submit`, stale-safe), brief decide/PDF, comments and report PDF routes. Props use the `CampaignPresenter::forPortal` allowlist; another client's campaign is a 404. See `campaigns.md`.
- 2026-10-18: Step I-2.6 — the shared `portal.pendingApprovals` count is cached per client for 60 s
  (`SharedPropsCache`) and busted whenever a post is created, changes status, or is deleted/restored
  (`Post::booted`). Step I-2.3: the portal calendar loads only the visible window too.
- 2026-10-16: Rebuilt `/review/:token` (`pages/review/show.tsx`, UI only): two columns on desktop
  (`PublicShell width="xl"`, new) — the post rendered as it will look per channel via the shared
  `SocialPostPreview` (channel tabs when there are several) beside a sticky column with the studio
  note, a "Going out" schedule and the two decisions; on mobile it stacks note → post → schedule →
  decision. Fixes two display bugs: hashtags rendered doubled (`##preview`, as stored tags already
  carry `#`) and the schedule showed raw `2026-10-17 09:00:00` (now "Sat 17 Oct · 09:00"). The form
  posts via Wayfinder `review.decide` instead of a hard-coded URL. Portal dates (campaign ranges,
  "client since", invoice dates, post schedules) render via `lib/dates.ts`; the company page's
  monogram is the Deep Teal tile used on the studio's client profile.
- 2026-10-16: Brand-book overhaul: the portal overview's header and Ink approvals block merged into
  one `ChapterHero` (approval count as the hero numeral, "Start reviewing" as its CTA); the
  approvals list moved to a paper `Panel` so the page carries a single Ink block.
- 2026-10-16: Alima rebrand of the portal, `/review/:token` and invite pages: `PublicShell` gets a
  spectrum band and the Alima lock-up, studio notes become Ink pull-quotes in Poppins Light with a
  lime quote mark, Ink blocks use lime accents and a `spark` (lime) CTA instead of teal, and the
  `client-invitation` email is re-skinned. No route, prop or behaviour changes.
- 2026-10-16: Portal invoices no longer include drafts (index filters them out; `show` 404s a draft). Both pages use `InvoicePresenter::portal()`: a derived `overdue` status, the itemised breakdown (items, retainer), and, on the detail page while unpaid, the studio's payment instructions. Internal-only fields (recipient emails, reminder counts, void reasons) are never exposed. Portal users also get an `InvoiceIssued` database notification when an invoice is sent or a reminder goes out. See `billing.md`.
- 2026-10-16: **Standalone portal.** Client reviewers now live entirely in `/portal/*`:
  `RedirectClientReviewersToPortal` (alias `portal.redirect`) sends them from the internal
  `dashboard`, `calendar` (filters carried across), `needs-attention` and `posts.edit` routes to
  the portal equivalent (`posts.edit` → `portal.posts.show` when the post is theirs and
  client-visible, else `portal.posts.index`). This removed the internal-dashboard links that 403'd
  for reviewers (Review queue / Needs attention → `/home`) and closed exposures through the
  internal UI: `internal_only` comments + the activity log (editor), internal-stage drafts and
  org-wide publish-reliability stats (dashboard), and idea/draft/internal-review posts (calendar).
- 2026-10-16: `PostStatus::clientVisible()` / `isClientVisible()` replace the duplicated
  `VISIBLE_STATUSES` constants. `EnsureClientPortalAccess` now 404s any bound `{post}` in an
  internal status (show/decide/comment), and `?status=` filters with an internal status are ignored.
- 2026-10-16: New **portal calendar** — `GET /portal/calendar` (`portal.calendar`,
  `PortalCalendarController` + `GetPortalCalendarPostsAction`), rendering `portal/calendar` with the
  shared `ContentCalendar` component in single-client mode.
- 2026-10-16: Shared `portal.pendingApprovals` Inertia prop (`HandleInertiaRequests`, reviewers only)
  drives the "Posts" nav badge. `app.tsx` resolves `portal/*` pages to `ClientPortalLayout` (pages no
  longer self-wrap), and `AppLayout` swaps to `ClientPortalLayout` for reviewers on shared pages
  (Settings). The portal header gained the notification bell and an account menu (Settings / Log out),
  which previously only existed in the internal sidebar.
- 2026-10-16: Studio Paper redesign of every portal page (dashboard approval block, grouped posts list,
  side-by-side post review with two large decision buttons, campaign cards, invoice ledger with
  outstanding/paid summary, company profile). Shared `PortalPostRow` and `PlatformIcon` components.
- 2026-09-19: Added the client portal's read-only Campaigns list/detail:
  `Portal\PortalCampaignController` (`index`/`show`), the `portal.campaigns.index`/`show` routes,
  `portal/campaigns/index.tsx`/`show.tsx` (posts filtered to the same visible-status allowlist as
  `PortalPostController`), a new "Campaigns" nav item in `client-portal-layout.tsx`, and
  `EnsureClientPortalAccess` now also scopes a bound `{campaign}` route parameter. Full note in
  `.junie/modules/campaigns.md` (also covers the paired internal `campaigns.show`/
  `campaigns.edit` CRUD pages).
- 2026-09-19: `PortalInvoiceController::transform` now also returns `pdf_url`/
  `pdf_original_filename`, and `portal/invoices/show.tsx` renders the PDF inline (`<iframe>`) when
  present — see `.junie/modules/billing.md`'s full changelog entry for the org-side invoice
  detail/edit/PDF-upload feature this pairs with. Still fully read-only here: no upload/edit/delete
  affordance is exposed in the portal.
- 2026-09-18: Client-portal audit fixes: (1) `app-sidebar.tsx` no longer shows "Clients"/"Needs
  attention" to `Role::ClientReviewer` (both 403 for that role) — it now shows a "Client portal"
  link into `/portal` instead; (2) new `PortalClientController::show` + `portal.company` route +
  `portal/company.tsx` page give the client a way to view their own company record, plus a
  "Company" nav item in `client-portal-layout.tsx` and a globally-shared `client` Inertia prop via
  `HandleInertiaRequests`; (3) `posts/edit.tsx`'s "Editor" tab now renders a read-only view of the
  caption/hashtags/targets/media instead of nothing when `can.update` is false; (4) `calendar/
  index.tsx` now links a `client_reviewer` viewer to `portal.posts.show` (which already has the
  approve/request-changes UI) instead of the internal editor; (5) the "missing invoices" symptom
  was the same root cause as (1) — `PortalInvoiceController` itself was already correct and fully
  tested, the client simply had no way to reach `/portal` in the first place. Added
  `tests/Feature/PortalClientControllerTest.php` and a regression case in
  `PostEditorControllerTest.php`.
- 2026-09-18: `PortalInvoiceController::index` (read-only invoices list, scoped by
  `client_id`, newest `issue_date` first, `is_unpaid` flag for any non-`paid` status), the
  `portal.invoices.index` route, the `portal/invoices/index.tsx` page, an updated
  `portal/invoices/show.tsx` (unpaid flag, issue date), the client-portal layout's "Invoices" nav
  link now pointing at the real list instead of the dashboard, and
  `tests/Feature/PortalInvoiceControllerTest.php` (Step 6.6). No create/edit/send/mark-paid
  actions are exposed — reuses `EnsureClientPortalAccess`'s existing `{invoice}` scoping.
- 2026-09-18: `App\Http\Controllers\Portal\PortalPostController::index`/`decide`/`comment`,
  `App\Http\Requests\Portal\PortalPostDecisionRequest`, the `portal.posts.index`/`decide`/
  `comments.store` routes, the `portal/posts/index.tsx` list page, a fully-built `portal/posts/
  show.tsx` (approve/request-changes + comment forms), and `tests/Feature/
  PortalPostControllerTest.php` (Step 6.5). Reuses `TransitionPostStatusAction`/
  `CreatePostCommentAction`/`PostPolicy` as-is — no new transition/comment logic.
- 2026-09-18: `App\Actions\Portal\GetPortalDashboardItemsAction`, updated
  `PortalDashboardController` and `portal/dashboard.tsx` to render real waiting-approval/
  changes-requested/upcoming-scheduled-post content scoped to the client, plus
  `tests/Feature/PortalDashboardTest.php` (Step 6.4).
- 2026-09-18: `App\Http\Middleware\EnsureClientPortalAccess` (aliased `portal`), the `/portal`
  route group (`portal.dashboard`, `portal.posts.show`, `portal.invoices.show`), thin
  `Portal\PortalDashboardController`/`PortalPostController`/`PortalInvoiceController`, the
  `client-portal` layout, and minimal `portal/dashboard`/`portal/posts/show`/
  `portal/invoices/show` Inertia pages (Step 6.3).
- 2026-09-18: Initial `ClientInvitation` model/migration/factory, `CreateClientInvitationAction`,
  `ClientInvitationMail` + blade view, `ClientInvitationPolicy`, `StoreClientInvitationRequest`,
  `ClientInvitationController` (store/destroy), routes (+ preview mirror), and the "Portal access"
  tab on `clients/show.tsx` (Step 6.1).
- 2026-09-18: `AcceptClientInvitationAction`, `AcceptClientInvitationRequest`,
  `ClientInvitationAcceptController` (`show`/`store`), the unauthenticated `client-invite/{token}`
  GET/POST routes, and the `client-invite/show.tsx` Inertia page (Step 6.2).
- 2026-09-18: Fixed the "Portal access" stat card on `clients/show.tsx`, which was counting only
  pending (unaccepted) invitations and therefore showed 0 once an invitation had been accepted; it
  now counts invitations with `accepted_at` set and `revoked_at` unset.
