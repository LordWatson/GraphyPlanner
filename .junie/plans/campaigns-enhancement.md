# Graphy — Campaigns Enhancement Plan

_Written 2026-10-18, against the code as it stands after Steps I-1.5, I-1.6 and I-2.4._

Today a `Campaign` is a label with dates. It has a name, a status (planning/active/completed/
archived), start and end dates, a free-text goal and notes, and it lists the posts that point at it
through `posts.campaign_id`. Nothing in the app plans, tracks, measures or bills against a campaign,
and the client portal shows it as a read-only list.

This plan turns the campaign into **the unit of work an agency sells and delivers**: a brief the
client signs off, a phased content plan, a production board, a live run the team can pause or shift,
and a branded wrap report that proves what it achieved. It becomes a selling point because it
matches how agencies already talk to clients ("the spring launch", "Black Friday"), not how a
scheduler stores posts.

This file sits alongside `.junie/plans/graphy-development-steps.md` (what to build),
`.junie/plans/improvements.md` (what to fix and deepen) and `.junie/plans/business-workspaces.md`.
How to use it (same rules as those plans):

- Each step is sized to hand to Junie as one unit of work: a goal, the files and models it touches,
  and a **Done when**.
- Every step follows `.junie/AGENTS.md`: thin controllers (Form Request → Action/Service →
  response), one-job Actions in `App\Actions\Campaigns\*`, reusable logic in `App\Services\*`,
  interfaces in `App\Contracts\*` where swappable, `DB::transaction()` around multi-write logic,
  logging at boundaries with ids (never secrets or raw prompts), and PHPUnit coverage (class-based,
  extending `Tests\TestCase`: happy path, role rejections, edge cases, regression test for bugs).
- **Every step updates `.junie/modules/campaigns.md`** (created in C-0.1) in the same change, plus
  any other module doc it touches (`posts.md`, `client-portal.md`, `ai.md`, `analytics.md`,
  `billing.md`, `calendar.md`, `home.md`). Use the `sync-module-docs` skill.
- PHPStan level 7 passes with no new ignores or baseline entries. SQL works on MySQL 8 and on the
  in-memory SQLite test database.
- Frontend steps follow `.junie/brand-guidelines.md` and use the `graphy-frontend-page` skill:
  house components (`Page`, `PageHeader`, `ChapterHero`, `Panel`, `StatStrip`, `EmptyState`,
  `StatusPill`), semantic tokens only, Wayfinder routes, UI gated on a `can` prop.
- Step ids are prefixed `C-` (e.g. `Step C-2.3`) so they never collide with `Step 2.3`, `I-2.3` or
  `B-2.3`. Mark finished steps `✅ Done` with an "Implemented via …" note.
- Priority tags: **[MVP]** needed before campaigns can be sold as a feature · **[V1]** makes it a
  clear reason to pick Alima · **[Stretch]**.

---

## 1. Product framing

### 1.1 The pitch

> Plan it, sign it off, ship it, prove it. One campaign, one place.

Most schedulers treat a campaign as a tag on a post. Agencies don't sell posts. They sell a launch,
a season, a push, with a brief, a deadline, a fee and a result. Alima should be the tool where that
whole arc lives, and where the client sees it as a single, polished story.

### 1.2 The lifecycle this plan builds

```
Brief ──► Plan ──► Produce ──► Approve ──► Run ──► Report
 C-2       C-3       C-4         C-5        C-6      C-7
                 ▲ workspace, hub and health (C-1) frame every stage ▲
                 ▲ fees and invoicing (C-8) sit alongside ▲
```

| Stage | What the agency gets | What the client sees |
|---|---|---|
| Brief | A structured brief, AI-drafted from the goal and Brand Brain | A brief to approve in the portal, with a record of who signed it |
| Plan | Phases (teaser, launch, sustain), a deliverables matrix, templates, an AI plan | A timeline of what's coming |
| Produce | A campaign board, campaign defaults (hashtags, link, asset kit), readiness score | Nothing until posts reach "Waiting on client" |
| Approve | Batch review per campaign | "Approve all" for the campaign, one feedback thread |
| Run | Pause, resume, shift dates, a launch checklist | A live view of what's out and what's next |
| Report | KPI targets vs actuals, a branded wrap report with AI-drafted commentary | A report page and PDF |

### 1.3 Principles

1. **The campaign plans, the post produces.** Deliverables are planned slots. A post is created
   from a slot when work starts. We never flood the posts table with empty placeholders.
2. **Derived, never stored, for anything judgemental.** Campaign health, readiness and timeline
   state are computed with human-readable reasons, the same way `ClientHealthService` works.
3. **Status is intent, dates are fact.** `campaigns.status` stays human-controlled. Whether a
   campaign is upcoming, live or ended comes from its dates. Mismatches ("ended 3 days ago, still
   Active") surface in Needs attention instead of auto-flipping status behind the team's back.
4. **AI suggests, humans accept.** AI drafts briefs, plans and wrap commentary. Nothing it writes
   reaches a client, a post or a report until a person accepts it.
5. **Reuse the engine.** Posts, the transition map, `ReschedulePostAction`, the analytics core
   (`AnalyticsScope` already narrows by campaign), PDFs (`PdfBrand`) and the portal are reused, not
   forked.

### 1.4 Role scoping (applies to every step)

| Capability | Owner | Strategist | Designer | Viewer | ClientReviewer |
|---|---|---|---|---|---|
| View campaign, plan, board | ✅ | ✅ | ✅ | ✅ | Own client only, posts from "Waiting on client" onward |
| Create/edit campaign, brief, plan, phases | ✅ | ✅ | — | — | — |
| Start a post from a deliverable | ✅ | ✅ | ✅ | — | — |
| Edit the asset kit | ✅ | ✅ | ✅ | — | — |
| Approve brief, batch approve posts | — | — | — | — | ✅ (own client) |
| Pause / resume / shift dates | ✅ | ✅ | — | — | — |
| See fee, budget, invoices, profitability | ✅ | ✅ | — | — | **Never** |
| See wrap report | ✅ | ✅ | ✅ | ✅ | Only once shared, never billing figures |
| Manage templates | ✅ | ✅ | — | — | — |

Billing visibility follows Phase 17's revenue rule (Owner/Strategist). The `CampaignPresenter`
(C-0.3) is the single place that drops restricted fields, so a portal or Designer prop can never
carry them by accident.

### 1.5 Out of scope

- Paid ads management, ad spend sync or bidding (spec §13).
- Time tracking. Profitability in C-8 is fee vs. invoiced vs. AI cost, not hours.
- Cross-client campaigns. A campaign belongs to exactly one client. An agency running the same
  idea for five clients uses a template (C-3.4).

### 1.6 Dependencies on other plans

| Needed for | Step elsewhere | Why |
|---|---|---|
| C-4.2 per-platform defaults | I-3.1 per-platform caption variants | Campaign defaults should fill the per-target fields, not only the master caption |
| C-4.3 board | I-4.1 content hub board | Share the kanban component instead of building two |
| C-4.4 assignments | I-4.4 assignees and due dates | Deliverable due dates hand off to `posts.due_at` |
| C-5.2 batch approval | I-8.2 multi-approver approvals | Batch approve must go through the same approval-step logic once it exists |
| C-6.1 pause/resume | I-1.2 safe rescheduling ✅ | Unscheduling relies on stale-job protection |
| C-7.1 engagement KPIs | Phase 12 / I-10.1 | Engagement actuals show "Available once engagement tracking is on" until then |
| C-7.3 link KPIs | I-10.4 link tracking | Campaign UTMs feed click data |
| C-3.5 calendar bands | I-5.2 planning layers | Campaign bands are one of those layers; build them here, reuse there |

None of these block the MVP. Where a dependency hasn't landed, the step ships its own part and
leaves a clearly named seam.

---

## Phase C-0 — Foundations

Goal: give campaigns a real shape in code before adding features on top.

### Step C-0.1 — Split the module doc and record decisions [MVP] ✅ Done
- Split `.junie/modules/campaigns-assets.md` into `campaigns.md` and `assets.md`. Campaigns is
  about to grow past what one shared doc can hold. Move the history across verbatim, with a
  changelog line in each pointing at the other.
- Add §1.3 (principles) and §1.4 (role scoping) of this plan to `campaigns.md` under
  "Business Rules".
- Update `.junie/modules/README.md`'s module table and every doc that links to
  `campaigns-assets.md`.
- **Done when**: no file in `.junie/` references `campaigns-assets.md`, and both new docs follow the
  README template.
- Implemented via `campaigns.md` + `assets.md` split; principles and role table recorded. See `.junie/modules/campaigns.md`.

### Step C-0.2 — Campaign data model v2 [MVP] ✅ Done
- Migration adding to `campaigns`:
  - `owner_id` (nullable FK `users`, null on delete, indexed): the accountable strategist.
  - `objective` (nullable, `App\Enums\CampaignObjective`: awareness, engagement, traffic, leads,
    sales, launch, community, other).
  - `colour` (nullable, `App\Enums\CampaignColour`: a fixed set of brand accent token names, never
    hex), used for calendar bands and chips.
  - `platforms` (json, nullable): intended `Platform` values. Advisory, used by the planner and
    readiness.
  - `hashtags` (json, nullable): campaign hashtag set (C-4.2).
  - `default_link_url` (nullable string) and `utm_campaign` (nullable string, slug-safe).
  - `code` (nullable string, unique per client): short reference such as `SPR26`, shown on chips
    and invoices.
- Keep `goal` as the short "what does success look like" line. Long-form content moves to the brief
  (C-2.1).
- `App\Enums\CampaignStatus` gains `Paused`. Labels in sentence case.
- Update `CampaignFactory` (states per status and objective), `StoreCampaignRequest`/
  `UpdateCampaignRequest` (owner must be in-org staff, `code` unique per client, colour/objective/
  platform enums), `CreateCampaignAction`/`UpdateCampaignAction`, and `DemoContentSeeder`.
- Indexes: `campaigns(client_id, status)`, `campaigns(org_id, start_date)`.
- **Done when**: tests cover each new rule, including a foreign-org `owner_id` rejected with a
  validation error, a duplicate `code` within one client rejected, and the same `code` accepted on
  a different client.
- Implemented via migrations `2026_10_19_100000`–`100700`, new enums and models, factories. See `.junie/modules/campaigns.md`.

### Step C-0.3 — Status transitions and activity log [MVP] ✅ Done
- `App\Actions\Campaigns\CampaignStatusTransitionMap`, mirroring `PostStatusTransitionMap`:
  planning → active/archived; active → paused/completed; paused → active/completed;
  completed → archived/active (reopen); archived → planning (restore).
- `TransitionCampaignStatusAction` (row lock, `DB::transaction()`, side effects after commit as
  I-1.4 established) and `InvalidCampaignTransitionException`.
- `campaign_activity_logs` (`campaign_id`, `user_id`, `event`, `from`, `to`, `meta` json,
  `created_at`) written by every campaign Action from here on. Model `CampaignActivityLog`.
- `UpdateCampaignRequest` no longer accepts `status`. Status changes go through
  `POST /campaigns/{campaign}/transition` (`TransitionCampaignRequest`).
- **Done when**: every legal transition passes, every illegal one returns 422 with no write, and
  each transition writes exactly one activity row (tests per role, including Designer forbidden).
- Implemented via `TransitionCampaignStatusAction` + `CampaignStatusTransitionMap`, `campaign_activity_logs`. See `.junie/modules/campaigns.md`.

### Step C-0.4 — Presenter and derived state services [MVP] ✅ Done
- Move `CampaignController::transform()`/`transformPost()` into
  `App\Support\Campaigns\CampaignPresenter` (like `ClientTabPresenter`), with
  `forStaff(Campaign, User)` and `forPortal(Campaign)`. The portal variant can't emit billing
  fields, internal notes or pre-review posts. `PortalCampaignController` switches to it.
- `App\Support\Campaigns\CampaignTimeline` value object: `upcoming` / `live` / `ended` / `undated`,
  plus `daysToLaunch`, `daysLeft` and `ranFor` (the numbers `campaigns/show.tsx` computes in
  TypeScript today, moved server-side so portal and staff agree).
- `App\Services\CampaignHealthService` (derived, never stored, each result with a reason):
  - **Red**: launch within 3 days with any deliverable not yet approved; a scheduled post's social
    account has an expired token; status Active while the end date passed more than 7 days ago.
  - **Amber**: no owner; no dates; brief not approved once the campaign is Active (after C-2);
    fewer than half the deliverables started inside 7 days of launch (after C-3).
  - **Green**: otherwise.
  Rules that depend on later phases return nothing until those tables exist. Write them behind a
  small `CampaignHealthRule` interface so each phase adds its rule without editing the service.
- **Done when**: unit tests cover every rule boundary (exactly 3 days, exactly 7 days, timezone of
  the org), and a portal props test asserts no billing or internal keys appear.
- Implemented via `CampaignPresenter`, `CampaignTimeline`, `CampaignHealthService` (tagged rules), `CampaignReadinessService`. See `.junie/modules/campaigns.md`.

---

## Phase C-1 — Campaign workspace and hub

Goal: make campaigns visible and feel alive, before any new planning features exist.

### Step C-1.1 — Org-wide campaigns hub [MVP] ✅ Done
- `GET /campaigns` → `campaigns.index` (`CampaignIndexController` + `ListCampaignsAction`). One
  page for every campaign across clients, filterable by client, status, timeline state, owner,
  objective and health, sortable by start date, and paginated server-side.
- Views: **list** (client, campaign chip with colour and code, status pill, timeline, owner,
  posts shipped / planned, health pill) and **timeline** (a horizontal Gantt of the next 90 days,
  one row per campaign, coloured bands, today line).
- A `StatStrip`: live now, launching in 14 days, at risk (red health), ended this month.
- Navigation: add "Campaigns" as a sub-item of chapter 04 Clients in `resources/js/lib/chapters.ts`.
  **Decision:** don't renumber chapters. Phase 17 is already moving Staff and Settings, and a
  second renumber in the same quarter costs more than it buys.
- Copy: headline "Every campaign, in one place." Empty state: "No campaigns yet. Start one from a
  client and it will show up here."
- ClientReviewer is redirected to `portal.campaigns.index`.
- **Done when**: filter, sort and pagination are covered by feature tests, the hub never returns
  another org's campaigns (tenant test as in I-1.9), and every health pill carries its reason as
  a tooltip.
- Implemented via `CampaignIndexController` + `ListCampaignsAction` (list, timeline, stats); `campaigns/index.tsx`. See `.junie/modules/campaigns.md`.

### Step C-1.2 — Campaign workspace page [MVP] ✅ Done
- Rebuild `campaigns/show.tsx` as a tabbed workspace on its own routes (same lazy pattern as the
  client tabs from I-2.4): **Overview**, **Brief** (C-2), **Plan** (C-3), **Board** (C-4),
  **Assets** (C-4), **Approvals** (C-5), **Results** (C-7), **Activity**.
- Tabs for phases that haven't shipped stay hidden, not disabled.
- Overview: `ChapterHero` with campaign name, client, status pill, colour chip and the
  `CampaignTimeline` line ("Launches in 6 days." / "Live. 12 days left." / "Ran for 30 days.");
  `StatStrip` (planned, in production, approved, scheduled, published); health panel with reasons;
  next 5 scheduled posts; owner; quick actions gated on `can`.
- Activity: paginated `campaign_activity_logs`, merged with post activity for the campaign's
  posts (transitions, approvals), newest first.
- Split the page into components under `resources/js/components/campaigns/`. No file over ~300
  lines (same bar as I-4.6).
- **Done when**: each tab route has a props test per role, and a Designer sees the page with no
  edit actions.
- Implemented via `CampaignWorkspaceController` + `CampaignLayout`; Overview, Brief, Plan, Board, Assets, Approvals, Results, Activity tabs. See `.junie/modules/campaigns.md`.

### Step C-1.3 — Campaign context everywhere a post appears [MVP] ✅ Done
- Campaign chip (colour + code) on post cards in the client Posts tab, calendar items, Home,
  the post editor header and portal post pages.
- Post editor: the campaign select becomes a searchable combobox showing only the client's
  non-archived campaigns; picking one shows its hashtags and default link as a hint (applied in
  C-4.2).
- Calendar and client Posts tab gain a campaign filter (`GetCalendarPostsAction` already accepts
  `campaign_id`).
- **Done when**: the chip renders on every listed surface (props tests), and the editor rejects a
  campaign from another client (validation test).
- Implemented via campaign chip on post rows, calendar, editor (`CampaignField`) and client tabs. See `.junie/modules/campaigns.md`.

### Step C-1.4 — Campaigns in Needs attention and Home [V1] ✅ Done
- `GetNeedsAttentionItemsAction` gains campaign items, computed in SQL (keep I-2.2's rule): red
  health campaigns, status/date mismatches ("Ended 3 days ago, still Active."), campaigns launching
  in 3 days with unapproved work.
- Home's overview gets a "Live campaigns" panel.
- **Done when**: each item type has a test, and the Home query count doesn't grow with the number
  of campaigns (query-count assertion).
- Implemented via `campaigns` bucket in `GetNeedsAttentionItemsAction`, home lane, dashboard live campaigns. See `.junie/modules/campaigns.md`.

---

## Phase C-2 — Brief and sign-off

Goal: a campaign starts with a brief the client approves, and the approval is on record.

### Step C-2.1 — Structured campaign brief [MVP] ✅ Done
- `campaign_briefs` (1:1 with `campaigns`): `background`, `objective_detail`, `audience`,
  `key_messages` (json list), `offer`, `call_to_action`, `mandatories` (json list),
  `avoid` (json list, merged with Brand Brain `weNeverSay[]` for guardrails),
  `deliverables_summary`, `success_measures`, `status` (`App\Enums\BriefStatus`: draft, sent,
  approved, changes_requested), `approved_at`, `approved_by` (nullable FK users).
- `UpdateCampaignBriefAction` and `UpdateCampaignBriefRequest`. Editing an approved brief puts it
  back to draft and logs `brief.reopened`, so a signed brief can't change silently.
- Brief tab: a sectioned editor using ruled spec rows ("A — Background", "B — Audience" …) to match
  the brand book. Read-only for Designer/Viewer.
- `ClientContextBuilder` includes the brief (when present) whenever it builds context for a post in
  the campaign, so captions and ideas follow it.
- **Done when**: tests cover create/update per role, the reopen-on-edit rule, and that the AI
  context includes the brief for a campaign post and excludes it for a post with no campaign.
- Implemented via `CampaignBrief` + `UpdateCampaignBriefAction`; `campaigns/brief.tsx`. See `.junie/modules/campaigns.md`.

### Step C-2.2 — AI brief drafting [V1] ✅ Done
- `DraftCampaignBriefAction`: from the campaign name, goal, objective, dates and the client's Brand
  Brain, the AI returns a suggested brief. Add `draftCampaignBrief()` to `AiProvider`, implemented
  in `DeepSeekProvider` and `NullAiProvider` (returns an empty, valid suggestion).
- The suggestion fills the editor as a diff the user accepts per section or all at once. It's never
  saved without a click.
- Runs through `AiGuardrailService` (never-say phrases), writes an `ai_usage_logs` row, respects the
  per-org AI toggle and budgets once 2.7/2.8 land, and is rate limited like the other AI endpoints
  (I-1.7). Raw prompts are not logged and don't reach the frontend.
- **Done when**: a test with a fake provider asserts nothing is persisted until accept, guardrail
  hits are flagged in the suggestion, and the Null provider path returns 200 with an empty draft.
- Implemented via `CampaignAiProvider::draftCampaignBrief` + `DraftCampaignBriefAction`; `brief-ai-draft.tsx`. See `.junie/modules/campaigns.md`.

### Step C-2.3 — Client brief approval [MVP] ✅ Done
- "Send brief to client" (Owner/Strategist) moves the brief to `sent`, snapshots it into
  `campaign_brief_revisions` (full content json + `content_hash`), and emails portal contacts
  (`CampaignBriefSentMail`, queued, sent after commit).
- Portal: `portal/campaigns/show` gains a Brief section with Approve / Request changes (with a
  required comment). Decisions are recorded against the revision id and hash.
- Staff get a notification (`CampaignBriefDecided`) and an activity row.
- `GET /campaigns/{campaign}/brief/pdf`: a branded brief PDF via `PdfBrand`, including the approval
  stamp ("Approved by … on …, revision 3"). Same document is downloadable from the portal.
- **Done when**: a ClientReviewer can only decide on their own client's sent brief (403/404
  otherwise), a decision on a stale revision is rejected, and the PDF contains the approved hash.
- Implemented via revisions with content hash, `SendCampaignBriefAction`, `DecideCampaignBriefAction`, signed PDF. See `.junie/modules/campaigns.md`.

---

## Phase C-3 — Plan

Goal: turn the brief into a phased, dated content plan the team can execute and the client can
follow.

### Step C-3.1 — Campaign phases [MVP] ✅ Done
- `campaign_phases`: `campaign_id`, `name`, `starts_on`, `ends_on`, `sort`, `colour`
  (`CampaignColour`). Must sit within the campaign dates (validated; a phase may be undated while
  the campaign is undated).
- Default suggestion when a dated campaign is created with no phases: Teaser · Launch · Sustain ·
  Wrap, split across the range. Offered as a one-click "Use suggested phases", not auto-created.
- CRUD + reorder (`ReorderCampaignPhasesAction`, single transaction).
- **Done when**: tests cover the date-bounds rule, reorder atomicity, and shrinking the campaign
  dates when phases would fall outside (rejected with a clear message).
- Implemented via `CampaignPhase` + phase actions, suggested phases, Gantt strip. See `.junie/modules/campaigns.md`.

### Step C-3.2 — Deliverables matrix [MVP] ✅ Done
- `campaign_deliverables`: `campaign_id`, `campaign_phase_id` (nullable), `title`, `platform`
  (`Platform`), `format` (nullable string until I-3.2's enum exists), `pillar` (nullable, from
  Brand Brain pillars), `planned_for` (date), `planned_time` (nullable), `assignee_id` (nullable),
  `notes`, `post_id` (nullable, unique FK `posts`, null on delete), `sort`.
- Plan tab: a matrix of phases (rows) by platform (columns) with deliverable cards, plus a list
  view. Each card shows its state, derived from the linked post: Not started · In production ·
  In review · Approved · Scheduled · Published.
- **Start post** (`StartPostFromDeliverableAction`): creates an Idea post for the campaign with the
  deliverable's title as a working caption, the target account for that platform (the client's
  only connected account for it, or a choice if several), and the planned date/time on the target.
  Lands in `posts.edit` (I-4.2 behaviour).
- Bulk add: "Add N per week on {platforms} through {phase}".
- **Done when**: a deliverable can be planned, started and followed through to Published with its
  derived state correct at each step, deleting the post returns the deliverable to Not started, and
  starting twice is rejected (unique `post_id`).
- Implemented via `CampaignDeliverable` + save/bulk/start actions; `plan/*` components. See `.junie/modules/campaigns.md`.

### Step C-3.3 — AI campaign planner [V1] ✅ Done
- `PlanCampaignAction`: from the brief, phases, platforms, dates, Brand Brain pillars and recent
  post history (`ClientContextBuilder`), the AI suggests deliverables: title, platform, format,
  pillar, phase, date, and a one-line angle. Add `planCampaign()` to `AiProvider`.
- Suggestions render in a review drawer. The user ticks the ones to keep. Accepted ones become
  `campaign_deliverables`. Nothing is written before that.
- Respects existing posting cadence (warn when a day already has posts for that account) and
  `Platform` rollout gating (no suggestions for platforms the org can't publish to).
- Same guardrails, usage logging, rate limits and Null-provider behaviour as C-2.2.
- **Done when**: tests assert suggestions never persist without accept, gated platforms never appear,
  and accepted suggestions land with correct phase and date.
- Implemented via `PlanCampaignAction` / `AcceptCampaignPlanAction`; `ai-planner.tsx`. See `.junie/modules/campaigns.md`.

### Step C-3.4 — Campaign templates [V1] ✅ Done
- `campaign_templates` (org-scoped): `name`, `description`, `objective`, `duration_days`, brief
  skeleton (json), phases (json: name, start offset, length, colour), deliverables (json: title,
  platform, format, pillar, phase index, day offset, time).
- **Save as template** from any campaign (`CreateCampaignTemplateAction`: converts dates to
  offsets from the start date, strips client-specific text, keeps placeholders like
  `{client_name}`).
- **New campaign from template** (`CreateCampaignFromTemplateAction`): pick client, start date, and
  optionally which platforms to include. Phases and deliverables are created relative to the start
  date in one transaction.
- Seed three starter templates per new org: Product launch (4 weeks), Seasonal sale (2 weeks),
  Always-on month (4 weeks). Agencies can edit or delete them.
- **Done when**: a round trip (campaign → template → new campaign 60 days later) reproduces phases
  and deliverables with shifted dates, client-specific fields are not copied, and templates are
  never visible to another org.
- Implemented via `CampaignTemplate`, create/use/starter actions, `campaigns/templates.tsx`. See `.junie/modules/campaigns.md`.

### Step C-3.5 — Campaigns on the calendar [V1] ✅ Done
- Calendar month and week views draw campaign bands (colour + name) across their date range, with
  phase sub-bands on hover. Toggleable layer, remembered per user in `localStorage`.
- Planned-but-not-started deliverables appear as ghost items on their date, so gaps in the plan are
  visible next to real posts. Clicking one opens Start post.
- Built as the first of I-5.2's planning layers. Record that in `calendar.md` so I-5.2 reuses it.
- **Done when**: `CalendarWindow` returns only campaigns overlapping the visible window (props
  test), and ghost items never appear in the portal calendar.
- Implemented via `GetCalendarCampaignLayerAction`; bands + ghosts in `content-calendar.tsx`. See `.junie/modules/campaigns.md`.

---

## Phase C-4 — Produce

Goal: make it faster to produce on-brief work inside a campaign than outside one.

### Step C-4.1 — Campaign asset kit [V1] ✅ Done
- The Assets tab shows assets with `campaign_id = this campaign` and lets the team add from the
  client library (`AssetLibraryDialog` in a new `pick-for-campaign` mode) or upload straight into the
  campaign.
- Optional asset requirements per deliverable ("1 × 9:16 video") as a small checklist on the card.
  Readiness (C-4.5) counts unmet ones.
- The post editor's "Browse library" opens filtered to the post's campaign first, with a toggle to
  see the whole client library.
- **Done when**: adding/removing an asset from the kit doesn't delete the asset, a Designer can
  manage the kit, and the editor's default filter is covered by a props test.
- Implemented via `UpdateCampaignAssetKitAction`, `campaigns/assets.tsx`, editor kit scope. See `.junie/modules/campaigns.md`.

### Step C-4.2 — Campaign defaults on posts [MVP] ✅ Done
- When a post joins a campaign (create or edit), `ApplyCampaignDefaultsAction` offers: add the
  campaign hashtag set, set the default link, append UTM parameters
  (`utm_campaign`, `utm_source` = platform, `utm_medium` = social). Offered as a dismissible prompt
  in the editor, never applied silently to existing content.
- When I-3.1 lands, defaults fill per-target overrides instead of the master caption. Until then,
  master only. Leave the seam named in `posts.md`.
- `EvaluatePostChecklistAction` adds a soft check: "Campaign hashtag missing" (warning, not a
  blocker).
- **Done when**: tests cover apply, dismiss, the UTM builder (existing query strings preserved,
  values URL-encoded), and the checklist warning.
- Implemented via `ApplyCampaignDefaultsAction`, `UtmLink`, soft hashtag checklist item. See `.junie/modules/campaigns.md`.

### Step C-4.3 — Campaign board [MVP] ✅ Done
- Board tab: kanban of the campaign's posts by status, with planned deliverables as an extra
  "Not started" column. Drag between columns calls the existing transition endpoint and only
  offers legal drop targets from `PostStatusTransitionMap::allowedTransitions`.
- Build the board as a shared component so I-4.1's content hub uses the same one. If I-4.1 lands
  first, reuse it here instead.
- Filters: phase, platform, assignee.
- **Done when**: an illegal drop isn't offered in the UI and is rejected server-side, and a
  Viewer gets a read-only board.
- Implemented via `GetCampaignBoardAction` + generic `kanban-board.tsx`. See `.junie/modules/campaigns.md`.

### Step C-4.4 — Assignments and due dates on deliverables [V1] ✅ Done
- `assignee_id` and a derived due date (planned date minus the client's approval lead time,
  default 3 working days, configurable per client) on deliverables.
- When a post is started from a deliverable, assignee and due date carry over to `posts.assignee_id`
  / `posts.due_at` once I-4.4 exists. Until then, they stay on the deliverable and show on the board.
- Notifications: assignment (`CampaignDeliverableAssigned`), due tomorrow, overdue. Only the
  assignee is notified.
- **Done when**: due-date maths is unit tested across weekends and the org timezone, and
  notifications target only the assignee.
- Implemented via assignees, `DeliverableDueDate`, `SendDeliverableDueRemindersAction` (daily command). See `.junie/modules/campaigns.md`.

### Step C-4.5 — Readiness score [MVP] ✅ Done
- `CampaignReadinessService` (derived, never stored): percentage of deliverables that are Approved
  or later, weighted by how soon they're due, plus a list of blockers with reasons ("3 posts still
  in internal review, launch in 4 days.", "No Instagram account connected.", "2 asset
  requirements unmet.").
- Shown on Overview, in the hub list and in the campaign health rules (C-0.4).
- **Done when**: unit tests cover weighting, each blocker type, and a campaign with no deliverables
  (readiness "Not planned", not 0% or 100%).
- Implemented via `CampaignReadinessService` + `ReadinessMeter`. See `.junie/modules/campaigns.md`.

### Step C-4.6 — Bulk actions on campaign posts [V1] ✅ Done
- From the Board or Plan: move a selection of posts between phases, reassign, submit all drafts
  for internal review, send all internally approved posts to the client (one email per batch, not
  per post).
- Each item goes through `TransitionPostStatusAction`. The response reports which succeeded and
  which were skipped with the reason (same pattern as I-4.1).
- **Done when**: a mixed selection transitions the legal ones, skips and reports the rest, and the
  client receives exactly one email.
- Implemented via `BulkUpdateCampaignPostsAction` (one client email per batch). See `.junie/modules/campaigns.md`.

---

## Phase C-5 — Client experience

Goal: the client sees their campaign as one polished story, and approves it in one sitting.

### Step C-5.1 — Portal campaign page v2 [MVP] ✅ Done
- `portal/campaigns/show`: hero with campaign name, dates and timeline line; brief section (C-2.3);
  phase timeline; posts grouped by phase with `StatusPill`s; a "Needs your review" count with a
  jump link.
- Only posts from "Waiting on client" onward. Deliverables never appear (they're internal). No
  billing fields, no internal notes, no health or readiness reasons (these mention internal
  states).
- Mobile first: check at 375 px (I-8.6's bar).
- **Done when**: a portal props test asserts the allowlist of keys and post statuses, and a
  reviewer from another client gets 404.
- Implemented via `PortalCampaignController::show` v2 with the `forPortal` allowlist. See `.junie/modules/campaigns.md`.

### Step C-5.2 — Batch approval per campaign [V1] ✅ Done
- "Review this campaign" in the portal: a focused flow through every post waiting on the client,
  one at a time with previews, then **Approve all remaining** with an optional comment.
- Each decision goes through the existing approval path. When I-8.2 lands, through its approval
  steps. Record that seam in `client-portal.md`.
- Staff get one summary notification per batch, not one per post.
- **Done when**: batch approve with one post already changed by staff mid-review only approves the
  revision the client saw (stale revision rejected and reported), and notifications are batched.
- Implemented via `BatchReviewCampaignPostsAction`; `portal/campaigns/review.tsx`. See `.junie/modules/campaigns.md`.

### Step C-5.3 — Campaign feedback thread [V1] ✅ Done
- One campaign-level conversation in the portal and on the staff Overview tab
  (`campaign_comments`, with @mentions using `CommentMentionParser`), for feedback that isn't about
  a single post ("Can we push the launch a week?").
- Internal-only comments stay internal, using the same flag pattern as post comments.
- **Done when**: internal comments never reach the portal (test), and mentions notify only the
  mentioned staff.
- Implemented via `CampaignComment` + `CreateCampaignCommentAction`; `campaign-conversation.tsx`. See `.junie/modules/campaigns.md`.

### Step C-5.4 — Shareable campaign preview [Stretch] ✅ Done
- A tokenized, read-only, expiring link to a campaign's approved and scheduled posts as a visual
  grid, for stakeholders without portal access (the client's CEO, a franchise owner). Token hashed
  at rest like `ReviewToken`. Revocable. No decisions possible through it.
- **Done when**: the token is hashed, revocation and expiry are tested, and only statuses from
  "Approved" onward appear.
- Implemented via `CampaignShareLink` + create/revoke/show actions, `share/campaign.tsx`, `share-links-dialog.tsx`. See `.junie/modules/campaigns.md`.

---

## Phase C-6 — Run

Goal: the campaign can be steered while it's live without editing posts one by one.

### Step C-6.1 — Pause and resume [V1] ✅ Done
- `PauseCampaignAction`: transitions the campaign to Paused and moves every **Scheduled** post in
  it back to Approved (unscheduling through the existing path, relying on I-1.2's stale-job
  protection), recording the affected post ids in the activity log meta. Never touches Publishing,
  Published or Failed posts.
- `ResumeCampaignAction`: returns to Active and offers to reschedule the paused posts: keep their
  original times if still in the future, or shift all by the paused duration.
- Confirmation dialog states the count: "Pause Spring launch? 7 scheduled posts will stop."
- **Done when**: a post that moves to Publishing between the confirm and the pause is left alone
  (row lock test), and resume restores exactly the set that was paused.
- Implemented via `PauseCampaignAction` / `ResumeCampaignAction`; Scheduled → Approved "Unschedule". See `.junie/modules/campaigns.md`.

### Step C-6.2 — Shift campaign dates [V1] ✅ Done
- `ShiftCampaignAction`: move the campaign, its phases, its deliverables and its not-yet-published
  posts by N days. Each post target keeps its own local time in its own timezone (the dual-timezone
  model), via `ReschedulePostAction`.
- Preview before apply: a list of what moves and what can't (published posts, targets that would
  land in the past).
- **Done when**: a London/Dubai test shifts a two-target post by 7 days and each target keeps its
  local wall-clock time, and published posts are untouched.
- Implemented via `ShiftCampaignAction` (preview + apply via `ReschedulePostAction`). See `.junie/modules/campaigns.md`.

### Step C-6.3 — Launch checklist and go-live [Stretch] ✅ Done
- A per-campaign launch checklist (template-driven, editable): brief approved, all launch-phase
  posts approved, accounts healthy, link tested, asset requirements met. Items auto-tick from
  derived state where possible; the rest are manual.
- When the first post publishes, notify the owner and log `campaign.went_live`.
- **Done when**: auto-ticked items follow their source state, and the go-live event fires once.
- Implemented via launch checklist in `GetCampaignOverviewAction`, `MarkCampaignWentLiveAction`. See `.junie/modules/campaigns.md`.

---

## Phase C-7 — Results and wrap report

Goal: every campaign ends with proof of what it delivered. This is what wins renewals.

### Step C-7.1 — KPI targets [MVP] ✅ Done
- `campaign_kpis`: `campaign_id`, `metric` (`App\Enums\CampaignMetric`), `target` (decimal),
  `sort`. Metrics available now from existing data: posts published, on-time rate (published at
  the planned time), approval turnaround (median hours from "Waiting on client" to decision),
  first-time approval rate, deliverables completed. Engagement metrics (reach, impressions,
  engagement rate, follower growth, link clicks) are listed but return
  `available: false` with "Available once engagement tracking is on." until Phase 12 / I-10.1 and
  I-10.4 land.
- `CampaignKpiService` computes actuals through the shared analytics services with an
  `AnalyticsScope` narrowed to the campaign. No new aggregation code.
- **Done when**: each available metric is tested on seeded data in the org timezone, and
  unavailable metrics never show a zero that looks like a real result.
- Implemented via `CampaignKpi` + `UpdateCampaignKpisAction` + `CampaignKpiService`. See `.junie/modules/campaigns.md`.

### Step C-7.2 — Results tab [MVP] ✅ Done
- Results tab: KPI target vs actual bars, a published-over-time series by phase, platform split,
  top posts (by engagement once available, by approval speed until then), and approval turnaround.
- Uses the dataviz conventions already used by `analytics/client.tsx`, and recharts.
- Link from org analytics: the campaign filter on `/analytics` gets a "Open campaign" link.
- **Done when**: props tests per role, and a Designer sees results without any revenue figures.
- Implemented via `GetCampaignResultsAction`; `campaigns/results.tsx`. See `.junie/modules/campaigns.md`.

### Step C-7.3 — Wrap report [V1] ✅ Done
- `campaign_reports` (one per campaign, versioned on publish): `summary`, `highlights` (json),
  `learnings`, `next_steps`, `kpi_snapshot` (json, frozen at publish), `status` (draft, shared),
  `shared_at`, `shared_by`.
- **Draft with AI** (`DraftCampaignWrapAction`, `AiProvider::draftCampaignWrap()`): writes summary,
  highlights and learnings from the KPI snapshot, the brief's success measures and top posts.
  Suggestion only, accepted per section. Same guardrails, logging and limits as C-2.2.
- **Share with client** freezes the KPI snapshot, publishes the report to the portal, and emails a
  branded PDF (`PdfBrand`, same pipeline as Phase 17 reports). Never includes fees or invoices.
- When I-10.3's monthly client reports exist, a shared wrap report appears in that month's report
  as a section.
- **Done when**: a shared report's numbers don't change when later data arrives (snapshot test),
  the portal shows only shared reports for the reviewer's own client, and the PDF contains no
  billing fields (isolation test).
- Implemented via `CampaignReport`, share with frozen snapshot + PDF, AI wrap draft. See `.junie/modules/campaigns.md`.

### Step C-7.4 — Compare campaigns [Stretch] ✅ Done
- Pick 2–4 campaigns (same client or across clients for staff) and compare KPIs side by side, with
  objective and duration shown so the comparison is fair.
- **Done when**: comparing campaigns from another org is a validation error, not an empty column.
- Implemented via `CompareCampaignsAction`, `CompareCampaignsRequest`, `campaigns/compare.tsx`. See `.junie/modules/campaigns.md`.

---

## Phase C-8 — Commercial

Goal: a campaign can be sold, invoiced and checked for profit, without leaving the app.

### Step C-8.1 — Campaign fee and budget [V1] ✅ Done
- `campaigns.fee_amount` (nullable, minor units, client currency) and `campaigns.billing_type`
  (`App\Enums\CampaignBillingType`: included_in_retainer, fixed_fee, none).
- Shown only to Owner/Strategist. Stripped by `CampaignPresenter` for everyone else and always for
  the portal (C-0.4).
- **Done when**: a props test per role confirms the fee only reaches Owner/Strategist, and
  validation rejects a fee on `included_in_retainer`.
- Implemented via `billing_type` / `fee_amount`, `viewBilling`, commercials panel. See `.junie/modules/campaigns.md`.

### Step C-8.2 — Invoice a campaign [V1] ✅ Done
- `invoice_items.campaign_id` (nullable FK, null on delete, indexed).
- "Invoice this campaign" (`CreateInvoiceFromCampaignAction`): creates a draft invoice for the
  client with a line item for the fee (description from campaign name and code), or adds it to the
  client's open draft if one exists. Goes through the existing invoice Actions so finalize, send and
  ledger rules apply unchanged.
- A campaign's Overview shows invoiced vs fee ("£2,400 of £3,000 invoiced.") to Owner/Strategist.
- **Done when**: invoicing twice warns about the existing line, a finalized invoice is never edited
  by this action, and `billing.md` documents the new column.
- Implemented via `CreateInvoiceFromCampaignAction` + `invoice_items.campaign_id`. See `.junie/modules/campaigns.md`.

### Step C-8.3 — Campaign profitability and revenue analytics [Stretch] ✅ Done
- Owner/Strategist panel: fee, invoiced, paid, AI cost for the campaign (from `ai_usage_logs` with
  a campaign id, added to logs for campaign AI actions from C-2.2 onward).
- Org analytics revenue section gains a "by campaign" breakdown, behind the same Phase 17 role rule.
- **Done when**: AI cost per campaign matches the usage logs on seeded data, and the section is
  absent for Designer/Viewer.
- Implemented via `CampaignPresenter::billing` (paid, AI spend), `RevenueMetrics::byCampaign`, estimated cost per campaign AI call. See `.junie/modules/campaigns.md`.

---

## Phase C-9 — Ship it as a selling point

Goal: agencies discover the feature, understand it in a minute, and see it working in the demo.

### Step C-9.1 — Demo data and preview mode [MVP] ✅ Done
- `DemoContentSeeder` and the preview fixture: one live campaign per demo client with a brief
  (approved), four phases, deliverables in every state, a paused campaign, a completed campaign
  with a shared wrap report, and KPI targets.
- **Done when**: `/preview` shows every campaign tab with realistic content, and preview mode stays
  read-only (existing preview tests extended).
- Implemented via `SeedsCampaignShowcase` in demo and preview seeders. See `.junie/modules/campaigns.md`.

### Step C-9.2 — Marketing page section [MVP] ✅ Done
- A "Campaigns" section on the marketing site (`marketing.md`), in the Alima voice. Draft copy:
  - Eyebrow: `CAMPAIGNS`
  - Headline: "Plan it, sign it off, ship it, prove it."
  - Body: "Every campaign gets a brief your client approves, a plan your team can follow, and a
    wrap report that shows what it achieved. One place, from first idea to final numbers."
  - Three points: "Briefs clients sign off." · "Plans that build themselves." · "Reports that win
    the renewal."
- One hero accent only. Screenshots from the preview fixture.
- **Done when**: the section passes `graphy-ui-reviewer`, and copy follows sentence case with full
  stops.
- Implemented via `components/marketing/campaigns-section.tsx` on the welcome page. See `.junie/modules/campaigns.md`.

### Step C-9.3 — First-run guidance [V1] ✅ Done
- Empty states that teach: the hub ("Start a campaign from a template and you'll have a plan in a
  minute."), the Plan tab ("Add phases, then plan what goes out when."), the Results tab ("Set a
  target or two and we'll track them for you.").
- A one-time "Start from a template" prompt on a client's first campaign.
- **Done when**: each empty state renders in its zero-data case (props tests).
- Implemented via hub/Plan/Results empty states and the client tab's first-campaign template prompt. See `.junie/modules/campaigns.md`.

### Step C-9.4 — Entitlement seam [Stretch] ✅ Done
- Gate templates, the AI planner, wrap reports and campaign invoicing behind a
  `CampaignFeatures` check that reads org settings today and plan entitlements once I-11.3 / B-9
  land. One class, no `if` branches spread through controllers.
- **Done when**: each gated feature returns 403 with a clear message when off, and the UI hides it
  via `can`.
- Implemented via `CampaignFeatures` contract + `SettingsCampaignFeatures`, `campaign.feature` middleware, `can` flags. See `.junie/modules/campaigns.md`.

---

## Suggested order and MVP cut

**MVP (sellable campaigns):** C-0.1 → C-0.4, C-1.1 → C-1.3, C-2.1, C-2.3, C-3.1, C-3.2, C-4.2,
C-4.3, C-4.5, C-5.1, C-7.1, C-7.2, C-9.1, C-9.2.

That gives an agency a campaign hub, a brief the client signs off, a phased plan, a board, a
readiness score, a client-facing campaign page and results against targets. Enough to demo and to
put on the pricing page.

**V1 (clear reason to pick Alima):** AI brief and planner (C-2.2, C-3.3), templates (C-3.4),
calendar bands (C-3.5), asset kit and assignments (C-4.1, C-4.4), bulk actions (C-4.6), batch
approval and feedback thread (C-5.2, C-5.3), pause/resume and shift (C-6.1, C-6.2), wrap report
(C-7.3), fees and invoicing (C-8.1, C-8.2), first-run guidance (C-9.3), Needs attention (C-1.4).

**Stretch:** C-5.4, C-6.3, C-7.4, C-8.3, C-9.4.

## Exit criteria

- An agency can go from a new campaign to a client-approved brief, a phased plan, produced and
  approved posts, a live run and a shared wrap report without leaving the campaign workspace.
- A ClientReviewer only ever sees their own client's campaigns, posts from "Waiting on client"
  onward, shared reports, and never a fee, invoice, readiness reason or internal comment.
- No AI output reaches a brief, plan, post or report without a person accepting it.
- Health, readiness and timeline state are derived with reasons, never stored.
- `.junie/modules/campaigns.md` reflects every shipped step, PHPStan level 7 passes, and
  `composer ci:check` is green.
