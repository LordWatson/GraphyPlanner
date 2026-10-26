# Campaigns

## Purpose
Campaigns are the unit of work an agency sells and delivers: a launch, a season, a push for one
client. Started as the campaign half of Step 0.7 (a label with dates grouping posts) and grown by
`.junie/plans/campaigns-enhancement.md` (Steps `C-*`) into the full lifecycle:
**brief → plan → produce → approve → run → report → bill**. Assets live in `assets.md` (split in
Step C-0.1); the kit link (`assets.campaign_id`) is documented on both sides.

## Models / Tables
- `Campaign` (`campaigns`): `org_id`, `client_id` (cascade), `owner_id` (nullable, in-org staff),
  `name`, `code` (uppercased, unique per client), `status` (`CampaignStatus`: planning / active /
  paused / completed / archived), `objective` (`CampaignObjective`), `colour` (`CampaignColour`:
  teal, electric, lilac, lime, ink — never Signal Orange), `platforms` (json), `start_date`,
  `end_date`, `goal`, `notes`, `hashtags` (json, stored without `#`), `default_link_url`,
  `utm_campaign` (slug), `billing_type` (`CampaignBillingType`: none / included_in_retainer /
  fixed_fee), `fee_amount` (decimal 12,2 — a fee is a contract figure, not a ledger amount, so it
  stays decimal rather than minor units; cleared unless fixed fee). Soft deletes. Indexes on
  `owner_id`, `(client_id, status)`, `(org_id, start_date)`, unique `(client_id, code)`.
  `recordActivity()` writes a `campaign_activity_logs` row.
- `CampaignActivityLog`: `event`, `from`, `to`, `meta` json, `user_id` (null for system events),
  `created_at` only. Events: `campaign.created`, `status.changed`, `campaign.resumed`,
  `campaign.shifted`, `campaign.went_live`, `brief.*`, `phase.*`, `deliverable.*`, `posts.bulk`,
  `assets.updated`, `comment.added`, `report.shared`, `invoice.added`.
- `CampaignBrief` (one per campaign): `CONTENT_FIELDS` (background, objective_detail, audience,
  key_messages[], offer, call_to_action, mandatories[], avoid[], deliverables_summary,
  success_measures), `status` (`BriefStatus`: draft / sent / approved / changes_requested),
  `approved_at/by`, `updated_by`. `hashContent()` is the SHA-256 of the canonical content.
- `CampaignBriefRevision`: immutable snapshot per send — `number`, `content`, `content_hash`,
  `sent_by/at`, `decision`, `decision_comment`, `decided_by/at`.
- `CampaignPhase`: `name`, `starts_on`, `ends_on`, `sort`, `colour`.
- `CampaignDeliverable`: a planned slot — `campaign_phase_id`, `title`, `platform`, `format`,
  `pillar`, `planned_for`, `planned_time`, `assignee_id`, `notes`, `asset_requirements` (json list
  of `{label, done}`), `post_id` (unique; set when work starts), `sort`. `state()` derives
  `DeliverableState` from the post (`fromPost()`); `unmetRequirementCount()`.
- `CampaignTemplate` (org-scoped): `name`, `description`, `objective`, `duration_days`, `brief`,
  `phases` and `deliverables` as day offsets (json), `is_starter`, `created_by`.
- `CampaignKpi`: `metric` (`CampaignMetric`), `target`, `sort`.
- `CampaignReport` (one per campaign): `summary`, `highlights`, `learnings`, `next_steps`,
  `kpi_snapshot` (frozen on share), `status` (`CampaignReportStatus`: draft / shared), `version`,
  `shared_at/by`.
- `CampaignComment`: `body`, `internal_only`, `user_id`.
- `CampaignShareLink` (C-5.4): `token_hash` (SHA-256, unique), `expires_at`, `revoked_at`,
  `last_viewed_at`, `view_count`, `created_by`. `isValid()`, `hash()`.
- Elsewhere: `posts.campaign_id` (+ `Post::deliverable()`), `assets.campaign_id` (the kit),
  `invoice_items.campaign_id`, `ai_usage_logs.campaign_id`, `clients.approval_lead_days`
  (working days, default 3), `organizations.campaign_features` (json overrides, C-9.4).
- Every FK column has an explicit index (`IndexCoverageTest`).

## Actions / Services / Contracts
All under `App\Actions\Campaigns` unless noted. Multi-write actions run in `DB::transaction`,
side effects (mail, notifications, publish jobs) in `DB::afterCommit`, and each logs ids at info.

- **Lifecycle**: `CreateCampaignAction` (defaults to Planning, `campaign.created`),
  `UpdateCampaignAction` (status is not editable here), `TransitionCampaignStatusAction` (row lock +
  re-check against `CampaignStatusTransitionMap`, optional `$within` closure run inside the lock),
  `PauseCampaignAction` (unschedules exactly the campaign's Scheduled posts → Approved, records the
  ids on the log), `ResumeCampaignAction` (`keep` original slots / `shift` by the paused days /
  `none`; skips with a reason anything that changed, would land in the past or fails the checklist),
  `ShiftCampaignAction` (`preview()` writes nothing; `apply()` moves campaign, phases, unstarted
  deliverables and movable posts via `ReschedulePostAction`, keeping each channel's local time).
- **Workspace data**: `BuildCampaignWorkspaceHeaderAction` (header, counters, `can`),
  `GetCampaignOverviewAction` (funnel, next five out, brief state, launch checklist),
  `GetCampaignPlanAction`, `GetCampaignBoardAction`, `GetCampaignResultsAction`,
  `ListCampaignActivityAction` (UNION of campaign + post activity), `ListCampaignsAction` (hub list,
  `timeline()`, `stats()`), `GetLiveCampaignsAction` (dashboard), `GetCalendarCampaignLayerAction`.
- **Brief**: `UpdateCampaignBriefAction` (editing an approved/sent brief reopens it to draft),
  `SendCampaignBriefAction` (revision + email), `DecideCampaignBriefAction` (portal; rejects a stale
  hash/revision), `GenerateCampaignBriefPdfAction` (approval stamp + content hash).
- **Plan**: `SaveCampaignPhaseAction`, `ReorderCampaignPhasesAction`, `ApplySuggestedPhasesAction`
  (`SuggestedPhases` by objective), `DeleteCampaignPhaseAction`, `SaveCampaignDeliverableAction`
  (notifies a new assignee), `BulkAddDeliverablesAction`, `StartPostFromDeliverableAction` (creates
  the post with campaign defaults via `ApplyCampaignDefaultsAction`).
- **Produce**: `BulkUpdateCampaignPostsAction` (submit / send to client / approve / phase / assign;
  per-post skip reasons; one `CampaignPostsReadyMail` per batch), `UpdateCampaignAssetKitAction`
  (tag/untag only, never deletes).
- **Client**: `BatchReviewCampaignPostsAction` (stale check on `seen_at` vs `updated_at`, quiet
  per-post notifications, one `CampaignReviewSubmitted`), `CreateCampaignCommentAction` (clients can't
  post internal; mentions resolve to in-org staff only).
- **Results**: `UpdateCampaignKpisAction`, `UpdateCampaignReportAction`,
  `ShareCampaignReportAction` (needs a summary; freezes `kpi_snapshot`; emails contacts the PDF),
  `GenerateCampaignReportPdfAction` (never fees or invoices).
- **AI** (C-2.2, C-3.3, C-7.3): `DraftCampaignBriefAction`, `PlanCampaignAction` (only
  rollout-enabled platforms, drops out-of-range dates, flags banned phrases rather than dropping,
  adds "already busy that day" warnings), `AcceptCampaignPlanAction`, `DraftCampaignWrapAction`.
  None persist; each call writes one `ai_usage_logs` row with `campaign_id`
  (`App\Services\Campaigns\CampaignAiUsage`). Planning runs on the queue: a reasoning model takes
  60s+ for a full plan, past the 60s web gateway timeout (nginx returned an HTML 504 that the UI
  showed as "Something went wrong" with nothing in the app log). `StartCampaignPlanAction` refuses
  synchronously when no platform is plannable, otherwise records a pending run
  (`CampaignPlanRuns`, cache, 1h, keyed by campaign + user) and dispatches `PlanCampaignJob` on the
  `ai` queue (`tries = 1`, `timeout = 140`); the job stores `done` + suggestions or `failed` +
  message, and the planner polls every 2s (gives up after 5 min).
- **Share links** (C-5.4): `CreateCampaignShareLinkAction` (plain token returned once; 1–90 days,
  default 14), `RevokeCampaignShareLinkAction` (idempotent), `ShowCampaignShareLinkAction` (unknown,
  expired or revoked → 404; posts from Approved onward only; counts views).
- **Compare** (C-7.4): `CompareCampaignsAction` — identity, objective, length, then every metric's
  actual vs target per campaign; metrics nobody can report are left out. The picker adds campaigns
  one chip at a time, so `CompareCampaignsRequest` accepts 1–4 ids (`ids` min:1, not min:2) — a
  single id is a valid intermediate selection that renders the "Add one more" state; the comparison
  itself only renders at ≥2 ids (controller guard).
- **Commercial**: `CreateInvoiceFromCampaignAction` (fixed fee only; asks for confirmation when
  already invoiced; adds to the latest non-finalized draft or a new invoice).
- **Templates**: `CreateCampaignTemplateAction`, `CreateCampaignFromTemplateAction` (lays phases,
  deliverables and a draft brief out from a start date), `SeedStarterCampaignTemplatesAction`
  (called by `RegisterOrganizationAction` and the seeders).
- **Go-live & reminders**: `MarkCampaignWentLiveAction` (from `TransitionPostStatusAction`'s
  after-commit; once per campaign), `SendDeliverableDueRemindersAction` (command
  `campaigns:send-due-reminders`, daily 08:15, in the `SchedulerHeartbeat` list).
- **Platforms**: `App\Services\Campaigns\CampaignPlatforms` — the one rule for what a campaign
  can plan for: platforms the client has an account on (connected, or token expired and needing a
  reconnect; never "not connected") with publishing switched on (`PlatformRolloutGate`).
  `plannable()` feeds the Plan tab's `platformOptions` and deliverable/bulk validation; `forAi()`
  (intended platforms ∩ plannable) feeds the AI planner and accepting its plan.
- **Services**: `CampaignHealthService` (`computeMany()`, pluggable `CampaignHealthRule`s tagged
  `campaign.health-rules`, each with `prime()` so a page is a fixed number of queries),
  `CampaignReadinessService` (weights 3/2/1; "Not planned" when there's nothing to score),
  `CampaignKpiService` (actuals from `ApprovalMetrics` with a campaign-scoped `AnalyticsScope`;
  engagement metrics carry "Available once engagement tracking is on.").
- **Health rules** (`App\Services\Campaigns\Health`): `LaunchingWithUnapprovedWorkRule`,
  `ExpiredAccountOnScheduledPostRule`, `StatusDateMismatchRule` (amber ≤ 7 days, red after),
  `BriefNotApprovedRule`, `DeliverablesNotStartedRule`, `PlannedPlatformNotConnectedRule`,
  `MissingBasicsRule`.
- **Entitlements** (C-9.4): `App\Contracts\CampaignFeatures` (bound to
  `App\Services\Campaigns\SettingsCampaignFeatures`: `config/campaigns.php` defaults, all on,
  overridden per org by `organizations.campaign_features`), enforced by the
  `campaign.feature:<feature>` middleware (`EnsureCampaignFeature`, 403 with a clear message) and
  folded into `can` (`saveTemplate`, `aiPlanner`, `wrapReports`, `invoice`; hub `can.templates`).
  Features (`CampaignFeature`): templates, ai_planner, wrap_reports, invoicing.
- **Contracts**: `App\Contracts\CampaignAiProvider` (kept apart from `AiProvider` so its test
  doubles don't grow three methods), bound in `CampaignServiceProvider` to `DeepSeekProvider` when
  AI is on with a key, else `NullAiProvider`.
- **Support** (`App\Support\Campaigns`): `CampaignTimeline`, `CampaignClock` (org timezone
  "today"), `CampaignHealth`/`CampaignHealthFinding`/`CampaignHealthContext`, `CampaignReadiness`,
  `CampaignPresenter` (`forStaff(..., withBilling)`, `forPortal` allowlist, `chip`, `post`,
  `portalPost`, `templateSummary`, `platformOptions`), `SuggestedPhases`, `DeliverableDueDate`
  (working days back from the planned date), `UtmLink`.
- **Policies**: `CampaignPolicy` — `viewAny`, `viewHub`, `view`, `create`, `update`, `transition`,
  `delete`, `produce` (Owner/Strategist/Designer), `viewBilling` (Owner/Strategist);
  `CampaignTemplatePolicy`.

## Phase M-3 additions (Step M-3.1 — C-7.1 / C-7.2 / C-7.3 switched on)
- `CampaignKpiService::actuals()` computes reach, impressions (views when a network only reports
  views), engagement rate, follower growth (the client's accounts over the campaign's range) and
  link clicks from `EngagementMetrics` over the campaign scope. Until a reading exists each reports
  `available: false` with `UNAVAILABLE_NOTE` ("No engagement readings yet…"), `NO_FOLLOWERS_NOTE`
  or `NO_CLICKS_NOTE` — never a zero. `CampaignMetric::isAvailable()` became
  `isWorkflowMetric()`; per-campaign availability comes from the actuals.
- `GetCampaignResultsAction::topPosts()` ranks by interactions when readings exist (else the
  fastest client sign-offs), each row saying `ranked_by`; the page title follows
  (`topPostsRankedBy`).
- Sharing a wrap report also freezes `campaign_reports.engagement_snapshot`
  (`CampaignKpiService::engagementSnapshot()`: totals, rate, follower growth, top three posts, no
  thumbnails). Reports shared earlier keep `null` and show no engagement block. Shown in the
  portal, the studio's wrap panel (`EngagementSnapshotFigures`) and the wrap PDF.

## Phase M-2 additions

- Pause also takes back `publishing` posts the vendor is still holding (cancelled there first);
  shift recalls them before its transaction and re-submits after (M-2.5). Campaign defaults fill
  each channel's link (UTM `utm_source` = that platform) and own hashtag list
  (`ApplyCampaignDefaultsAction::applyToChannels()/linksFor()`, M-2.2).

## Routes / Controllers
Controllers are thin; Form Requests live in `App\Http\Requests\Campaigns` and `...\Portal`.
- Hub: `GET /campaigns` → `campaigns.index` (`CampaignIndexController`; `view=list|timeline`;
  filters `search`, `client`, `status` only, validated by `CampaignHubRequest`; ordered by start
  date, undated last). The stat strip is display-only.
- Client tab: `GET|POST /clients/{client}/campaigns` (`ClientTabController::campaigns`,
  `CampaignController::store` → redirects to the workspace). Sends `starterTemplates` (≤ 3) only
  while the client has no campaigns and the viewer can create one (C-9.3).
- Workspace (`CampaignWorkspaceController`): `campaigns.show` (Overview), `.brief`, `.plan`,
  `.board`, `.assets`, `.approvals`, `.results`, `.activity`; `campaigns.edit/update/destroy`
  (`CampaignController`), `campaigns.transition` (Paused goes through pause/resume).
- Brief (`CampaignBriefController`): `campaigns.brief.update`, `.send`, `.pdf`.
- Plan (`CampaignPlanController`): `campaigns.phases.*` (store, update, destroy, reorder,
  suggested), `campaigns.deliverables.*` (store, bulk, update, destroy, start).
- Board: `campaigns.board.move`; bulk: `campaigns.posts.bulk` (JSON).
- Kit: `campaigns.kit.store/destroy`. Comments: `campaigns.comments.store`.
- Results (`CampaignResultsController`): `campaigns.kpis.update`, `campaigns.report.update/share/pdf`.
- AI (`CampaignAiController`, `throttle:ai`, 404 when AI is off): `campaigns.ai.brief/plan/wrap`,
  `campaigns.plan.accept`. `campaigns.ai.plan` answers 202 `{run}`; `GET
  campaigns/{campaign}/ai/plan/{run}` → `campaigns.ai.plan.status` (outside `throttle:ai`, polled;
  404 for an unknown, expired or someone else's run).
- Run (`CampaignRunController`): `campaigns.pause`, `.resume`, `.shift.preview` (JSON), `.shift`.
- Compare: `GET /campaigns/compare?ids[]=…` → `campaigns.compare` (`CampaignCompareController`,
  `CompareCampaignsRequest`: 2–4 of the viewer's org's campaigns; registered before
  `campaigns/{campaign}`).
- Share links (`CampaignShareLinkController`): `campaigns.share-links.store` (JSON, 201) and
  `.destroy` (scoped `{shareLink}`); public `GET /share/campaigns/{token}` → `campaign-share.show`
  (`throttle:public-token`, page `share/campaign`, no layout, `noindex`).
- Invoice: `campaigns.invoice`. Templates: `campaign-templates.index/update/destroy/use`,
  `campaigns.template.store`.
- Portal (`Portal\PortalCampaignController`): `portal.campaigns.index/show`,
  `.brief.decide/pdf`, `.comments.store`, `.review` / `.review.submit`, `.report.pdf`.
- Preview mirror: GET routes for the hub, client tab and every workspace tab (`preview.*`).
- `RedirectClientReviewersToPortal` sends reviewers on staff campaign URLs to the portal page.

## Frontend
- Pages: `campaigns/index` (hub: list + timeline), `compare`, `share/campaign` (public), `overview`, `brief`, `plan`, `board`, `assets`,
  `approvals`, `results`, `activity`, `edit`, `templates`; `portal/campaigns/index`, `show`,
  `review`; `clients/tabs/campaigns` (with the first-campaign template prompt).
- Components (`components/campaigns/`): `campaign-layout` (header, transitions, pause/resume/shift/
  save-as-template dialogs, tabs), `campaign-chip`, `health-pill` (`HealthPill`, `ReadinessMeter`),
  `campaign-gantt`, `campaign-conversation`, `campaign-post-row`, `brief-ai-draft`,
  `ai-suggestion-sheet`, `new-campaign-dialog`, `use-template-dialog`, `calendar-layer`,
  `live-campaigns-panel`, `portal-brief`, `share-links-dialog`, `plan/*`, `results/*`; generic `kanban/kanban-board`.
- Elsewhere: campaign chip on post rows, calendar and editor; editor `CampaignField`; calendar bands
  and ghost deliverables; home "Campaigns" lane; dashboard live campaigns; marketing section
  (`components/marketing/campaigns-section.tsx`); nav sub-item and command palette entry.
- Status colours come from `lib/status.ts` (`campaign`, `brief`, `deliverable`, `timeline` kinds).

## Business Rules

### Principles (plan §1.3)
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
   (`AnalyticsScope` narrows by campaign), PDFs (`PdfBrand`) and the portal are reused, not forked.

### Role scoping (plan §1.4)
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

### Other rules
- Status transitions follow `CampaignStatusTransitionMap`; an illegal move is a validation error on
  `to` (422 for JSON). Pausing and resuming only go through their own endpoints.
- The portal sees `CampaignPresenter::forPortal` (identity + timeline) and nothing else: no owner,
  notes, billing, readiness, health or internal comments. Another client's campaign is a 404.
- The hub never carries billing (`withBilling: false`); the workspace only for `viewBilling`.
- A brief decision must match the revision and content hash the client saw; anything else is
  refused as stale. Editing the brief afterwards reopens it to draft.
- A client batch review only records a decision if the post hasn't changed since the client opened
  it; the studio gets one summary notification per batch, not one per post.
- Comment mentions (`@[Name](id)`) only notify in-org staff; internal comments never reach the
  client. Notification bodies show a plain-text excerpt.
- A shared report's KPI snapshot is frozen; later target changes don't alter it. Reports and PDFs
  never contain fees or invoices.
- Campaign hashtags are a **soft** checklist item in the editor: shown as a warning, never block
  scheduling. A post can only join its own client's campaign.
- Unscheduling (Scheduled → Approved) is a post transition ("Unschedule"); it creates no approval
  row because it isn't a new decision.
- Deliverable due date = planned date minus the client's `approval_lead_days` working days.
  Reminders go to the assignee only, the day before it's due and the day after it's missed, and
  skip ready work and campaigns that aren't Planning/Active.
- Went-live fires once per campaign, on its first published post.
- The AI planner never suggests a platform that can't be published to (`PlatformRolloutGate`).
- Only the client's channels can be planned. The Plan tab offers just those (with a "Connect
  accounts" prompt when there are none); a slot already on a channel the client no longer has keeps
  its column and can be edited, but can't be moved to another missing channel, and new slots can't
  be added there. The AI planner is only asked about, and only keeps, the client's channels.
- Templates are org-scoped; another org's template is a 403 to use and invisible in the gallery.
- Profitability (C-8.3): the Commercials panel shows fee, invoiced, paid (one grouped query) and
  AI spend (est., USD, summed from `ai_usage_logs.cost_estimate`). AI spend follows the analytics
  `viewAiCost` rule (Owner only), stricter than billing; Strategists get `null`. Campaign DeepSeek
  calls record `ai.deepseek.estimated_cost_per_call`. Org analytics' revenue section adds
  `by_campaign` (billed lines tagged with a campaign, per currency, top 10).
- Comparing campaigns from another organization is a validation error on `ids.*`, never an empty
  column.
- Share links never show drafts, reviews, comments, notes or billing, and accept no decisions. A
  dead link is a plain 404 so it doesn't confirm a token ever existed.
- Gated features off → 403 from the middleware and hidden in the UI; already-shared reports stay
  readable in the portal.

## Seeders
- `Database\Seeders\Concerns\SeedsCampaignShowcase` (used by `DemoContentSeeder` and
  `PreviewSeeder`): a live campaign with an approved brief and revision, four phases, deliverables in
  every state, KPIs and comments; a paused campaign; a completed campaign with a shared report;
  backdated activity; starter templates.

## Tests
- `tests/Feature/CampaignControllerTest.php`, `tests/Feature/PortalCampaignControllerTest.php`.
- `tests/Feature/Campaigns/`: `CampaignLifecycleTest`, `CampaignBriefTest`, `CampaignPlanTest`,
  `CampaignWorkspacePropsTest` (role props, billing, portal allowlist, hub filters + isolation,
  first-run empty states and template prompt), `CampaignRunTest` (pause/resume, shift across DST,
  invoicing, templates), `CampaignCollaborationTest` (bulk email, batch review, mentions, report
  snapshot), `CampaignOperationsTest` (went-live, reminders, AI planner, asset kit, editor scoping),
  `CampaignSurfacesTest` (calendar layer, needs attention + query count, soft checklist, demo seed),
  `CampaignFeaturesTest`, `CampaignPlanRunTest` (queued planner: 202 + poll, failure message,
  run privacy), `CampaignProfitabilityTest`, `CampaignCompareTest`, `CampaignShareLinkTest`.
- `tests/Unit/Services/CampaignHealthServiceTest`, `CampaignReadinessServiceTest`;
  `tests/Unit/Support/Campaigns/CampaignTimelineTest`, `UtmLinkTest`.
- `CrossTenantRouteTest` has fixtures for `CampaignPhase`, `CampaignDeliverable`,
  `CampaignTemplate`, `CampaignShareLink`.

## Known Gaps / Open Questions
- No UI yet for an organization (or the super admin) to flip `campaign_features`; it's set in the
  database or via env defaults until plan entitlements (I-11.3 / B-9) replace the binding.
- AI spend is a flat per-call estimate; swap for token-based pricing if DeepSeek usage is captured.
- Engagement KPIs (reach, impressions, engagement rate, follower growth, link clicks) show as
  unavailable until engagement tracking exists.
- The marketing section uses a live component vignette rather than preview-fixture screenshots.

## Changelog
- 2026-10-24: Fix — `/campaigns/compare` could never reach two campaigns. The old single-select "Add a campaign…" dropdown navigated with one id per pick, but `CompareCampaignsRequest` required `ids` min:2, so the first pick failed validation and bounced back with nothing selected. Two changes: (1) relaxed validation to `ids` min:1 (a single id is a valid intermediate selection; the comparison still renders only at ≥2 via the controller guard; removed the dead `ids.min` message); (2) replaced the single-select with a true multi-select `MultiCombobox` (`resources/js/components/multi-combobox.tsx`) — check several campaigns in one pass without the popover closing, capped at `max` (4). The page keeps an optimistic local selection (`picked`) so toggles are instant and don't race the round-trip, reconciled to the server's `selected` on change; navigation uses `preserveState` to keep the popover open. Selected campaigns still show as removable chips. Regression test `test_a_single_campaign_is_a_valid_intermediate_selection`. See `frontend.md` › components.
- 2026-10-24: UI — the campaigns hub (`pages/campaigns/index.tsx`) now opens with the Ink `ChapterHero` in place of `PageHeader`, matching the other primary chapter pages; "New campaign" renders as the `spark` CTA with "Compare"/"Templates" as `on-ink` secondaries. The aside is omitted because the hub's stats are `Deferred` (the `StatStrip` stays below). See `frontend.md`.
- 2026-10-23: Step M-5.3 — `BatchReviewCampaignPostsAction` now judges a batch-review decision stale by the **content** the client saw (a `post_revisions` revision created after their `seen_at`), not by `updated_at`, so a schedule tweak or autosave no longer forces a re-review. Posts with no history fall back to `updated_at`. See `posts.md` › Phase M-5 › M-5.3.
- 2026-10-21: Step M-3.1 — engagement KPIs on (reach, impressions, engagement rate, follower growth, link clicks), top posts by engagement, frozen `engagement_snapshot` on shared wrap reports (regression: earlier snapshots unchanged).
- 2026-10-20: Phase 18 (grid planner) — a grid plan can belong to a campaign; the workspace overview lists its grids (deferred `grids` prop, `GridLibrary::summary()`). `BulkUpdateCampaignPostsAction` now delegates status moves to the shared `App\Actions\Posts\BulkTransitionPostsAction` (extracted so grids, which may have no campaign, reuse it) — behaviour unchanged.
- 2026-10-19: "Plan with AI" moved onto the `ai` queue with a polled run status
  (`StartCampaignPlanAction`, `PlanCampaignJob`, `CampaignPlanRuns`) — fixes the 60s gateway
  timeout that showed "Something went wrong". JSON helpers now name gateway timeouts.
- 2026-10-20: Phase M-2 — pause/shift recall vendor-held posts; per-channel defaults.
- 2026-10-18: Planning limited to the client's channels (`CampaignPlatforms`) across the Plan tab,
  deliverable validation and the AI planner.
- 2026-10-18: Hub filters reduced to Search, Client and Status (timing, health, owner, objective
  and order removed end to end); stat tiles no longer apply filters.
- 2026-10-18: Stretch steps shipped — C-5.4 shareable preview links, C-7.4 compare campaigns,
  C-8.3 profitability + revenue by campaign, C-9.4 `CampaignFeatures` entitlement seam.
- 2026-10-18: Campaigns enhancement C-0 → C-9.3 shipped: data model v2, transitions + activity
  log, derived health/readiness/timeline, hub and workspace, brief + client sign-off, phases and
  deliverables, AI brief/planner/wrap, templates, calendar layer, asset kit, defaults, board,
  readiness, bulk actions, portal v2 + batch review + conversation, pause/resume/shift, launch
  checklist and go-live, KPIs, results, wrap report, fees and invoicing, demo data, marketing
  section and first-run guidance. Full feature test coverage added.
- 2026-10-18: Step C-0.1 — split out of the shared campaigns & assets doc into this file and
  `assets.md`; plan §1.3 principles and §1.4 role scoping recorded under Business Rules.
- 2026-10-18: Step I-2.4 — campaigns have their own client tab (`GET /clients/{client}/campaigns` →
  `clients.campaigns.index`, paginated).
- 2026-10-18: Step I-1.5 — `Campaign` uses `SoftDeletes`.
- 2026-10-16: Brand-book overhaul of the campaign detail page (since replaced by the workspace).
- 2026-09-19: Campaign detail/edit pair and the client portal's read-only counterpart.
- 2026-09-16: Campaigns moved to a tab on the client page; demo campaigns seeded.
- 2026-09-08: Changed `campaigns.goal` from `text` to `longText`.
- 2026-09-08: Initial `Campaign` model/migration/policy/actions/controller/requests (Step 0.7).
