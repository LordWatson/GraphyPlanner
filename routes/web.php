<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiImageController;
use App\Http\Controllers\Analytics\AnalyticsController as OrganizationAnalyticsController;
use App\Http\Controllers\Analytics\ClientReportController;
use App\Http\Controllers\Analytics\ReportController as OrganizationReportController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\FacebookInstagramConnectController;
use App\Http\Controllers\BrandBrainController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\Campaigns\CampaignAiController;
use App\Http\Controllers\Campaigns\CampaignAssetKitController;
use App\Http\Controllers\Campaigns\CampaignBoardController;
use App\Http\Controllers\Campaigns\CampaignBriefController;
use App\Http\Controllers\Campaigns\CampaignCommentController;
use App\Http\Controllers\Campaigns\CampaignCompareController;
use App\Http\Controllers\Campaigns\CampaignIndexController;
use App\Http\Controllers\Campaigns\CampaignInvoiceController;
use App\Http\Controllers\Campaigns\CampaignPlanController;
use App\Http\Controllers\Campaigns\CampaignPostBulkController;
use App\Http\Controllers\Campaigns\CampaignResultsController;
use App\Http\Controllers\Campaigns\CampaignRunController;
use App\Http\Controllers\Campaigns\CampaignShareLinkController;
use App\Http\Controllers\Campaigns\CampaignTemplateController;
use App\Http\Controllers\Campaigns\CampaignWorkspaceController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientInvitationAcceptController;
use App\Http\Controllers\ClientInvitationController;
use App\Http\Controllers\ClientTabController;
use App\Http\Controllers\ConnectInviteController;
use App\Http\Controllers\DailyBriefController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Grids\GridAiController;
use App\Http\Controllers\Grids\GridPlanController;
use App\Http\Controllers\Grids\GridPlanIndexController;
use App\Http\Controllers\Grids\GridProductionController;
use App\Http\Controllers\Grids\GridTileController;
use App\Http\Controllers\Grids\GridWorkflowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organizations\RegistrationController;
use App\Http\Controllers\Portal\PortalAccountConnectController;
use App\Http\Controllers\Portal\PortalCalendarController;
use App\Http\Controllers\Portal\PortalCampaignController;
use App\Http\Controllers\Portal\PortalClientController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalGridController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalPostController;
use App\Http\Controllers\Portal\PortalReportController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PublicConnectController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffInvitationAcceptController;
use App\Http\Controllers\StaffInvitationController;
use App\Http\Controllers\SuperAdmin\AnalyticsController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\OrganizationController as SuperAdminOrganizationController;
use App\Http\Controllers\SuperAdmin\ReportController;
use App\Http\Controllers\UploadPostWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('about', 'about')->name('about');

// Step 0.13: the client review portal is unauthenticated — access is granted purely by
// possession of a valid `ReviewToken`, never a logged-in session (spec §10/§12).
// Step I-1.7: every public token link is throttled per IP + token (`throttle:public-token`).
Route::middleware('throttle:public-token')->group(function () {
    Route::get('review/{token}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('review/{token}', [ReviewController::class, 'decide'])->name('review.decide');
    // Step 18.8: a grid review link's decision (approve / request changes, with flagged tiles).
    Route::post('review/{token}/grid', [ReviewController::class, 'decideGrid'])->name('review.grid.decide');

    // Campaigns enhancement C-5.4: a read-only campaign preview for stakeholders without portal access.
    Route::get('share/campaigns/{token}', [CampaignShareLinkController::class, 'show'])->name('campaign-share.show');

    // Step 6.2: accepting a client portal invitation is unauthenticated — access is granted purely
    // by possession of a valid, unexpired, unrevoked, unaccepted `ClientInvitation` token, mirroring
    // the review portal above.
    Route::get('client-invite/{token}', [ClientInvitationAcceptController::class, 'show'])->name('client-invite.show');
    Route::post('client-invite/{token}', [ClientInvitationAcceptController::class, 'store'])->name('client-invite.store');

    // Accepting a staff invitation is unauthenticated — access is granted purely by possession of a
    // valid, unexpired, unrevoked, unaccepted `StaffInvitation` token, mirroring the client portal
    // invitation flow above.
    Route::get('staff-invite/{token}', [StaffInvitationAcceptController::class, 'show'])->name('staff-invite.show');
    Route::post('staff-invite/{token}', [StaffInvitationAcceptController::class, 'store'])->name('staff-invite.store');

    // Step M-2.8: a client connects their own social accounts from a self-connect link — access
    // is possession of a live `ConnectInvite` token, scoped to its one client's account slots.
    Route::get('connect/{token}', [PublicConnectController::class, 'show'])->name('connect.show');
    Route::post('connect/{token}/accounts/{socialAccount}', [PublicConnectController::class, 'connect'])->name('connect.account');
});

// Step 1.3: Upload-Post redirects the browser back here once the user finishes the hosted
// connect flow, so this must be reachable without a session, just like the review portal above.
Route::get('social-accounts/callback', [SocialAccountController::class, 'callback'])->name('social-accounts.callback');

// Step 1.5: Upload-Post posts its publish-outcome webhook here — no session/CSRF token is
// available from a server-to-server vendor call, so this is public (verified instead via the
// `X-Upload-Post-Signature` header, see `UploadPostWebhookController`) and excluded from CSRF
// verification (see `bootstrap/app.php`).
Route::post('webhooks/upload-post', [UploadPostWebhookController::class, 'handle'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.upload-post');

// Step 15.2: the public organization signup — the first real "create an organization" path.
// Fortify's own `/register` (which created org-less users) is replaced by it; the old URL
// redirects here so existing links keep working.
Route::middleware('guest')->group(function () {
    Route::get('register-organization', [RegistrationController::class, 'create'])->name('organizations.register');
    Route::post('register-organization', [RegistrationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organizations.register.store');
});
Route::redirect('register', '/register-organization')->name('register');

Route::middleware(['auth', 'verified'])->group(function () {
    // Reachable by every logged-in role (including Role::ClientReviewer/portal users and super
    // admins), unlike the internal-only routes below — the notification bell is in every layout.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});

// The organization workspace. `org.member` (Step 15.1) sends org-less super admins to
// `/super-admin` — everything in here is scoped by the user's `org_id`.
Route::middleware(['auth', 'verified', 'org.member'])->group(function () {
    // Client portal contacts are sent to their `/portal/*` equivalents (see
    // `RedirectClientReviewersToPortal`) — the internal dashboard/calendar/home are staff-only UI.
    Route::middleware('portal.redirect')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');

        Route::get('home', [HomeController::class, 'index'])->name('needs-attention');
    });

    Route::resource('clients', ClientController::class);
    // Step I-2.4: one route per client page tab (`clients.show` is the Social accounts tab).
    Route::get('clients/{client}/posts', [ClientTabController::class, 'posts'])->name('clients.posts.index');
    Route::get('clients/{client}/media', [ClientTabController::class, 'media'])->name('clients.media');
    Route::get('clients/{client}/campaigns', [ClientTabController::class, 'campaigns'])->name('clients.campaigns.index');
    Route::get('clients/{client}/billing', [ClientTabController::class, 'billing'])->name('clients.billing');
    Route::get('clients/{client}/portal-access', [ClientTabController::class, 'portalAccess'])->name('clients.portal-access');
    Route::get('clients/{client}/ai', [ClientTabController::class, 'ai'])->name('clients.ai.index');
    Route::get('clients/{client}/briefs', [ClientTabController::class, 'briefs'])->name('clients.briefs');
    // Step I-1.5: "delete" (above) archives; these act on archived (soft-deleted) clients.
    Route::post('clients/{client}/restore', [ClientController::class, 'restore'])
        ->withTrashed()
        ->name('clients.restore');
    Route::delete('clients/{client}/purge', [ClientController::class, 'purge'])
        ->withTrashed()
        ->name('clients.purge');

    Route::get('clients/{client}/brand-brain', [BrandBrainController::class, 'edit'])
        ->name('clients.brand-brain.edit');
    Route::put('clients/{client}/brand-brain', [BrandBrainController::class, 'update'])
        ->name('clients.brand-brain.update');
    Route::post('clients/{client}/brand-brain/persona', [BrandBrainController::class, 'uploadPersona'])
        ->name('clients.brand-brain.persona.store');
    // Step 16.4: Brand Brain visual references for the AI Image Studio.
    Route::put('clients/{client}/brand-brain/references', [BrandBrainController::class, 'updateReferences'])
        ->name('clients.brand-brain.references.update');

    Route::post('clients/{client}/invoices', [InvoiceController::class, 'store'])
        ->name('clients.invoices.store');
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('invoices/export', [InvoiceController::class, 'export'])
        ->middleware(['throttle:exports', 'password.confirm'])
        ->name('invoices.export');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
        ->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->name('invoices.destroy');
    // Step I-1.6: invoice PDFs are private — streamed only through this authorized route.
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'showPdf'])
        ->name('invoices.pdf.show');
    Route::post('invoices/{invoice}/pdf', [InvoiceController::class, 'uploadPdf'])
        ->name('invoices.pdf.store');
    Route::post('invoices/{invoice}/generate-pdf', [InvoiceController::class, 'generatePdf'])
        ->middleware('throttle:exports')
        ->name('invoices.generate-pdf');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');
    Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])
        ->name('invoices.finalize');
    Route::post('invoices/{invoice}/remind', [InvoiceController::class, 'remind'])
        ->name('invoices.remind');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->name('invoices.void');
    Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])
        ->name('invoices.duplicate');

    Route::post('invoices/{invoice}/items', [InvoiceItemController::class, 'store'])
        ->name('invoices.items.store');
    Route::put('invoice-items/{invoiceItem}', [InvoiceItemController::class, 'update'])
        ->name('invoice-items.update');
    Route::delete('invoice-items/{invoiceItem}', [InvoiceItemController::class, 'destroy'])
        ->name('invoice-items.destroy');

    Route::post('clients/{client}/social-accounts', [SocialAccountController::class, 'store'])
        ->name('clients.social-accounts.store');
    // Step M-2.8: client self-connect links.
    Route::post('clients/{client}/connect-invites', [ConnectInviteController::class, 'store'])->name('clients.connect-invites.store');
    Route::delete('connect-invites/{connectInvite}', [ConnectInviteController::class, 'destroy'])->name('connect-invites.destroy');
    Route::put('social-accounts/{socialAccount}', [SocialAccountController::class, 'update'])
        ->name('social-accounts.update');
    Route::delete('social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])
        ->name('social-accounts.destroy');
    Route::post('social-accounts/{socialAccount}/connect', [SocialAccountController::class, 'connect'])
        ->name('social-accounts.connect');

    // Step 1.9.3: the additive Facebook Login connect flow that grants Meta Graph API access for
    // Instagram audio search (Step 1.9.4) — separate from, and does not replace, the Upload-Post
    // connect flow above. Kept in the authenticated browser tab (unlike the Upload-Post popup
    // flow) since Facebook's own OAuth redirect returns here directly.
    Route::get('clients/{client}/social-accounts/{socialAccount}/facebook/connect', [FacebookInstagramConnectController::class, 'redirect'])
        ->name('social-accounts.facebook.connect');
    Route::get('clients/{client}/social-accounts/{socialAccount}/facebook/callback', [FacebookInstagramConnectController::class, 'callback'])
        ->name('social-accounts.facebook.callback');

    // Static alternative to the dynamic callback above: Facebook Apps only allow a fixed,
    // pre-registered list of OAuth redirect URIs, so when `FACEBOOK_REDIRECT_URI` is configured
    // to a single static URL (e.g. in production), Facebook redirects here instead — the
    // `SocialAccount` being connected is recovered from the `state` query parameter.
    Route::get('auth/meta/callback', [FacebookInstagramConnectController::class, 'metaCallback'])
        ->name('auth.meta.callback');

    Route::post('clients/{client}/campaigns', [CampaignController::class, 'store'])
        ->name('clients.campaigns.store');

    // `.junie/plans/campaigns-enhancement.md` — the campaigns hub (C-1.1) and the workspace, one
    // route per tab (C-1.2). Client contacts are sent to their portal pages.
    Route::get('campaigns', CampaignIndexController::class)
        ->middleware('portal.redirect')
        ->name('campaigns.index');
    // C-7.4: before `campaigns/{campaign}` so "compare" never binds as a campaign.
    Route::get('campaigns/compare', CampaignCompareController::class)
        ->middleware('portal.redirect')
        ->name('campaigns.compare');
    // C-3.4: campaign templates.
    Route::get('campaign-templates', [CampaignTemplateController::class, 'index'])->middleware('campaign.feature:templates')->name('campaign-templates.index');
    Route::put('campaign-templates/{template}', [CampaignTemplateController::class, 'update'])->middleware('campaign.feature:templates')->name('campaign-templates.update');
    Route::delete('campaign-templates/{template}', [CampaignTemplateController::class, 'destroy'])->middleware('campaign.feature:templates')->name('campaign-templates.destroy');
    Route::post('campaign-templates/{template}/use', [CampaignTemplateController::class, 'use'])->middleware('campaign.feature:templates')->name('campaign-templates.use');
    Route::post('campaigns/{campaign}/template', [CampaignTemplateController::class, 'storeFromCampaign'])->middleware('campaign.feature:templates')->name('campaigns.template.store');
    Route::get('campaigns/{campaign}', [CampaignWorkspaceController::class, 'show'])
        ->middleware('portal.redirect')
        ->name('campaigns.show');
    Route::get('campaigns/{campaign}/brief', [CampaignWorkspaceController::class, 'brief'])->name('campaigns.brief');
    Route::get('campaigns/{campaign}/plan', [CampaignWorkspaceController::class, 'plan'])->name('campaigns.plan');
    Route::get('campaigns/{campaign}/board', [CampaignWorkspaceController::class, 'board'])->name('campaigns.board');
    Route::get('campaigns/{campaign}/assets', [CampaignWorkspaceController::class, 'assets'])->name('campaigns.assets');
    Route::get('campaigns/{campaign}/approvals', [CampaignWorkspaceController::class, 'approvals'])->name('campaigns.approvals');
    Route::get('campaigns/{campaign}/results', [CampaignWorkspaceController::class, 'results'])->name('campaigns.results');
    Route::get('campaigns/{campaign}/activity', [CampaignWorkspaceController::class, 'activity'])->name('campaigns.activity');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
        ->name('campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');
    Route::post('campaigns/{campaign}/transition', [CampaignController::class, 'transition'])
        ->name('campaigns.transition');
    // C-6.1 / C-6.2: steer a live campaign.
    Route::post('campaigns/{campaign}/pause', [CampaignRunController::class, 'pause'])->name('campaigns.pause');
    Route::post('campaigns/{campaign}/resume', [CampaignRunController::class, 'resume'])->name('campaigns.resume');
    Route::get('campaigns/{campaign}/shift', [CampaignRunController::class, 'previewShift'])->name('campaigns.shift.preview');
    Route::post('campaigns/{campaign}/shift', [CampaignRunController::class, 'shift'])->name('campaigns.shift');

    Route::put('campaigns/{campaign}/brief', [CampaignBriefController::class, 'update'])->name('campaigns.brief.update');
    Route::post('campaigns/{campaign}/brief/send', [CampaignBriefController::class, 'send'])->name('campaigns.brief.send');
    Route::get('campaigns/{campaign}/brief/pdf', [CampaignBriefController::class, 'pdf'])
        ->middleware('throttle:exports')
        ->name('campaigns.brief.pdf');

    Route::scopeBindings()->group(function () {
        Route::post('campaigns/{campaign}/phases', [CampaignPlanController::class, 'storePhase'])->name('campaigns.phases.store');
        Route::post('campaigns/{campaign}/phases/reorder', [CampaignPlanController::class, 'reorderPhases'])->name('campaigns.phases.reorder');
        Route::post('campaigns/{campaign}/phases/suggested', [CampaignPlanController::class, 'suggestedPhases'])->name('campaigns.phases.suggested');
        Route::put('campaigns/{campaign}/phases/{phase}', [CampaignPlanController::class, 'updatePhase'])->name('campaigns.phases.update');
        Route::delete('campaigns/{campaign}/phases/{phase}', [CampaignPlanController::class, 'destroyPhase'])->name('campaigns.phases.destroy');

        Route::post('campaigns/{campaign}/deliverables', [CampaignPlanController::class, 'storeDeliverable'])->name('campaigns.deliverables.store');
        Route::post('campaigns/{campaign}/deliverables/bulk', [CampaignPlanController::class, 'bulkDeliverables'])->name('campaigns.deliverables.bulk');
        Route::match(['put', 'patch'], 'campaigns/{campaign}/deliverables/{deliverable}', [CampaignPlanController::class, 'updateDeliverable'])->name('campaigns.deliverables.update');
        Route::delete('campaigns/{campaign}/deliverables/{deliverable}', [CampaignPlanController::class, 'destroyDeliverable'])->name('campaigns.deliverables.destroy');
        Route::post('campaigns/{campaign}/deliverables/{deliverable}/start', [CampaignPlanController::class, 'startDeliverable'])->name('campaigns.deliverables.start');

        Route::post('campaigns/{campaign}/board/posts/{post}', [CampaignBoardController::class, 'move'])->name('campaigns.board.move');

        Route::post('campaigns/{campaign}/kit', [CampaignAssetKitController::class, 'store'])->name('campaigns.kit.store');
        Route::delete('campaigns/{campaign}/kit/{asset}', [CampaignAssetKitController::class, 'destroy'])->name('campaigns.kit.destroy');
    });

    Route::put('campaigns/{campaign}/kpis', [CampaignResultsController::class, 'updateKpis'])->name('campaigns.kpis.update');
    Route::put('campaigns/{campaign}/report', [CampaignResultsController::class, 'updateReport'])->middleware('campaign.feature:wrap_reports')->name('campaigns.report.update');
    Route::post('campaigns/{campaign}/report/share', [CampaignResultsController::class, 'shareReport'])->middleware('campaign.feature:wrap_reports')->name('campaigns.report.share');
    Route::get('campaigns/{campaign}/report/pdf', [CampaignResultsController::class, 'reportPdf'])->middleware(['throttle:exports', 'campaign.feature:wrap_reports'])->name('campaigns.report.pdf');
    Route::post('campaigns/{campaign}/comments', [CampaignCommentController::class, 'store'])->name('campaigns.comments.store');

    // C-2.2 / C-3.3 / C-7.3: AI suggestions (never saved by themselves).
    Route::middleware('throttle:ai')->group(function () {
        Route::post('campaigns/{campaign}/ai/brief', [CampaignAiController::class, 'draftBrief'])->name('campaigns.ai.brief');
        Route::post('campaigns/{campaign}/ai/plan', [CampaignAiController::class, 'suggestPlan'])->middleware('campaign.feature:ai_planner')->name('campaigns.ai.plan');
        Route::post('campaigns/{campaign}/ai/wrap', [CampaignAiController::class, 'draftWrap'])->middleware('campaign.feature:wrap_reports')->name('campaigns.ai.wrap');
    });
    // Polled by the planner while PlanCampaignJob works, so outside `throttle:ai`.
    Route::get('campaigns/{campaign}/ai/plan/{run}', [CampaignAiController::class, 'planStatus'])->middleware('campaign.feature:ai_planner')->whereUuid('run')->name('campaigns.ai.plan.status');
    Route::post('campaigns/{campaign}/plan/accept', [CampaignAiController::class, 'acceptPlan'])->middleware('campaign.feature:ai_planner')->name('campaigns.plan.accept');
    Route::post('campaigns/{campaign}/posts/bulk', CampaignPostBulkController::class)->name('campaigns.posts.bulk');
    Route::post('campaigns/{campaign}/share-links', [CampaignShareLinkController::class, 'store'])->name('campaigns.share-links.store');
    Route::delete('campaigns/{campaign}/share-links/{shareLink}', [CampaignShareLinkController::class, 'destroy'])->scopeBindings()->name('campaigns.share-links.destroy');
    Route::post('campaigns/{campaign}/invoice', CampaignInvoiceController::class)->middleware('campaign.feature:invoicing')->name('campaigns.invoice');

    // Phase 18: the Instagram grid planner - plans per client, the planner page, tiles, the
    // workflow (Step 18.7), turning tiles into posts and scheduling the sequence (18.9-18.11).
    // The org-wide grids hub (a section of 04 Clients, like the campaigns hub).
    Route::get('grids', GridPlanIndexController::class)->middleware('portal.redirect')->name('grids.index');
    Route::get('clients/{client}/grids', [GridPlanController::class, 'index'])->name('clients.grids.index');
    Route::post('clients/{client}/grids', [GridPlanController::class, 'store'])->name('clients.grids.store');
    Route::get('grids/{grid}/edit', [GridPlanController::class, 'edit'])->middleware('portal.redirect')->name('grids.edit');
    Route::put('grids/{grid}', [GridPlanController::class, 'update'])->name('grids.update');
    Route::delete('grids/{grid}', [GridPlanController::class, 'destroy'])->name('grids.destroy');
    Route::post('grids/{grid}/duplicate', [GridPlanController::class, 'duplicate'])->name('grids.duplicate');
    Route::post('grids/{grid}/feed/refresh', [GridPlanController::class, 'refreshFeed'])->middleware('throttle:30,1')->name('grids.feed.refresh');
    Route::post('grids/{grid}/tiles', [GridTileController::class, 'store'])->name('grids.tiles.store');
    Route::post('grids/{grid}/tiles/reorder', [GridTileController::class, 'reorder'])->name('grids.tiles.reorder');
    Route::post('grids/{grid}/split', [GridTileController::class, 'split'])->name('grids.split');
    Route::scopeBindings()->group(function () {
        Route::put('grids/{grid}/tiles/{tile}', [GridTileController::class, 'update'])->name('grids.tiles.update');
        Route::delete('grids/{grid}/tiles/{tile}', [GridTileController::class, 'destroy'])->name('grids.tiles.destroy');
        Route::post('grids/{grid}/tiles/{tile}/sync-from-post', [GridProductionController::class, 'syncTileFromPost'])->name('grids.tiles.sync-from-post');
        Route::post('grids/{grid}/tiles/{tile}/restore-post', [GridProductionController::class, 'restorePost'])->name('grids.tiles.restore-post');
    });
    Route::post('grids/{grid}/transition', [GridWorkflowController::class, 'transition'])->name('grids.transition');
    Route::post('grids/{grid}/override-approval', [GridWorkflowController::class, 'overrideApproval'])->name('grids.override-approval');
    Route::post('grids/{grid}/comments', [GridWorkflowController::class, 'comment'])->name('grids.comments.store');
    Route::post('grids/{grid}/posts', [GridProductionController::class, 'createPosts'])->name('grids.posts.store');
    Route::post('grids/{grid}/posts/bulk', [GridProductionController::class, 'bulkMove'])->name('grids.posts.bulk');
    Route::get('grids/{grid}/schedule', [GridProductionController::class, 'previewSchedule'])->name('grids.schedule.preview');
    Route::post('grids/{grid}/schedule', [GridProductionController::class, 'applySchedule'])->name('grids.schedule');
    Route::post('grids/{grid}/schedule-approved', [GridProductionController::class, 'scheduleApproved'])->name('grids.schedule-approved');
    Route::post('grids/{grid}/hold', [GridProductionController::class, 'holdRest'])->name('grids.hold');
    Route::post('grids/{grid}/outside-posts/{post}', [GridProductionController::class, 'addOutsidePost'])->name('grids.outside-posts.store');
    // Step 18.12: AI suggestions for a grid (never saved by themselves).
    Route::middleware('throttle:ai')->group(function () {
        Route::post('grids/{grid}/ai/captions', [GridAiController::class, 'captions'])->name('grids.ai.captions');
        Route::post('grids/{grid}/ai/balance', [GridAiController::class, 'balance'])->name('grids.ai.balance');
    });
    Route::post('grids/{grid}/tiles/{tile}/ai/fill', [GridAiController::class, 'fillSlot'])->scopeBindings()->middleware('throttle:ai-images')->name('grids.tiles.ai.fill');
    Route::post('grids/{grid}/tiles/{tile}/ai/use', [GridAiController::class, 'useVariant'])->scopeBindings()->name('grids.tiles.ai.use');

    Route::get('clients/{client}/assets', [AssetController::class, 'index'])
        ->name('clients.assets.index');
    Route::post('clients/{client}/assets', [AssetController::class, 'store'])
        ->name('clients.assets.store');
    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])
        ->name('assets.destroy');

    Route::post('clients/{client}/posts', [PostController::class, 'store'])
        ->name('clients.posts.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])
        ->middleware('portal.redirect')
        ->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])
        ->name('posts.update');
    // Step I-1.5: soft delete for posts that never went out (published ones are archived).
    Route::delete('posts/{post}', [PostController::class, 'destroy'])
        ->name('posts.destroy');
    Route::post('posts/{post}/transition', [PostController::class, 'transition'])
        ->name('posts.transition');
    // Step I-3.8 / M-2.9: Owner-only approval override (with a reason).
    Route::post('posts/{post}/override-approval', [PostController::class, 'overrideApproval'])->name('posts.override-approval');
    Route::post('posts/{post}/reschedule', [PostController::class, 'reschedule'])
        ->name('posts.reschedule');
    // Step I-1.3: resend only the failed targets of a failed/partially published post.
    Route::post('posts/{post}/retry-failed-targets', [PostController::class, 'retryFailedTargets'])
        ->name('posts.retry-failed-targets');
    Route::post('posts/{post}/comments', [PostController::class, 'comment'])
        ->name('posts.comments.store');
    Route::post('posts/{post}/activity-logs/{activityLog}/resend-review-email', [PostController::class, 'resendReviewEmail'])
        ->name('posts.activity-logs.resend-review-email');
    Route::get('posts/{post}/music-search', [PostController::class, 'musicSearch'])
        ->middleware('throttle:music-search')
        ->name('posts.music-search');

    // Step 2.4: AI-assisted trends/ideas (client dashboard) and caption suggestions (post
    // editor) — see AiController, gated behind config('ai.enabled') and Owner/Strategist.
    // Step I-1.7: POST (they call a paid vendor and write rows — a GET can be prefetched, crawled
    // or replayed) and throttled per user + per org.
    Route::middleware('throttle:ai')->group(function () {
        Route::post('clients/{client}/ai/trends', [AiController::class, 'trends'])
            ->name('clients.ai.trends');
        Route::post('clients/{client}/ai/ideas', [AiController::class, 'ideas'])
            ->name('clients.ai.ideas');
        Route::post('posts/{post}/ai/captions', [AiController::class, 'captions'])
            ->name('posts.ai.captions');
    });

    // Trends & Ideas now persist (Step 2.4 follow-up) so each discovered trend/idea gets its
    // own detail page, the same list → detail pattern as the rest of the app.
    Route::get('ai-trends/{aiTrend}', [AiController::class, 'showTrend'])
        ->name('ai-trends.show');
    Route::get('ai-post-ideas/{aiPostIdea}', [AiController::class, 'showIdea'])
        ->name('ai-post-ideas.show');
    Route::post('ai-post-ideas/{aiPostIdea}/persist', [AiController::class, 'persistIdea'])
        ->name('ai-post-ideas.persist');

    // Phase 16: AI Image Studio — see AiImageController, gated behind config('ai.enabled') and
    // AiImageGenerationPolicy (Owner/Strategist write, Designer read-only, clients never).
    Route::post('ai-post-ideas/{aiPostIdea}/images', [AiImageController::class, 'generateForIdea'])
        ->middleware('throttle:ai-images')
        ->name('ai-post-ideas.images.store');
    Route::post('clients/{client}/ai/images', [AiImageController::class, 'generate'])
        ->middleware('throttle:ai-images')
        ->name('clients.ai.images.store');
    Route::get('ai-image-generations/{generation}', [AiImageController::class, 'show'])
        ->name('ai-image-generations.show');
    Route::post('ai-image-generations/{generation}/use', [AiImageController::class, 'useInPost'])
        ->name('ai-image-generations.use');
    Route::post('ai-image-variants/{variant}/refine', [AiImageController::class, 'refine'])
        ->middleware('throttle:ai-images')
        ->name('ai-image-variants.refine');
    Route::post('ai-image-variants/{variant}/regenerate', [AiImageController::class, 'regenerate'])
        ->middleware('throttle:ai-images')
        ->name('ai-image-variants.regenerate');
    Route::post('ai-image-variants/{variant}/finalize', [AiImageController::class, 'finalize'])
        ->name('ai-image-variants.finalize');
    Route::post('ai-image-variants/{variant}/discard', [AiImageController::class, 'discard'])
        ->name('ai-image-variants.discard');
    Route::get('ai-image-variants/{variant}/download', [AiImageController::class, 'download'])
        ->name('ai-image-variants.download');

    // Step 2.5: internal-only "Daily Briefs" tab listing the nightly DailyBrief rows for a
    // client, same gating as the AI endpoints above.
    Route::get('clients/{client}/daily-briefs', [DailyBriefController::class, 'index'])
        ->name('clients.daily-briefs.index');

    // Step 6.1: client portal invitations (Owner/Strategist only, see ClientInvitationPolicy).
    Route::post('clients/{client}/invitations', [ClientInvitationController::class, 'store'])
        ->name('clients.invitations.store');
    Route::delete('invitations/{invitation}', [ClientInvitationController::class, 'destroy'])
        ->name('invitations.destroy');

    // Phase 17: chapter 06 Analytics — every staff role (sections gated per role inside, see
    // `AnalyticsAccess`); client reviewers get a 403 from `OrganizationAnalyticsFilterRequest`.
    Route::get('analytics', [OrganizationAnalyticsController::class, 'index'])->name('analytics.index');
    // Step I-16.2 / M-1.2: raw data exports re-check the password (`password.confirm`, 3 h window).
    Route::get('analytics/export', [OrganizationAnalyticsController::class, 'export'])->middleware(['throttle:exports', 'password.confirm'])->name('analytics.export');
    Route::get('analytics/pdf', [OrganizationAnalyticsController::class, 'pdf'])->middleware('throttle:exports')->name('analytics.pdf');
    Route::get('clients/{client}/analytics', [OrganizationAnalyticsController::class, 'client'])->name('clients.analytics');
    // Step M-3.3: monthly client reports (studio side).
    Route::get('clients/{client}/reports', [ClientReportController::class, 'index'])->name('clients.reports.index');
    Route::post('clients/{client}/reports', [ClientReportController::class, 'store'])->name('clients.reports.store');
    Route::put('clients/{client}/report-schedule', [ClientReportController::class, 'updateSchedule'])->name('clients.report-schedule.update');
    Route::get('client-reports/{report}', [ClientReportController::class, 'show'])->name('client-reports.show');
    Route::put('client-reports/{report}', [ClientReportController::class, 'update'])->name('client-reports.update');
    Route::post('client-reports/{report}/refresh', [ClientReportController::class, 'refresh'])->name('client-reports.refresh');
    Route::post('client-reports/{report}/publish', [ClientReportController::class, 'publish'])->name('client-reports.publish');
    Route::get('client-reports/{report}/pdf', [ClientReportController::class, 'pdf'])->middleware('throttle:exports')->name('client-reports.pdf');
    Route::resource('analytics/reports', OrganizationReportController::class)
        ->names('analytics.reports')
        ->parameters(['reports' => 'report']);
    Route::get('analytics/reports/{report}/export', [OrganizationReportController::class, 'export'])->middleware(['throttle:exports', 'password.confirm'])->name('analytics.reports.export');
    Route::get('analytics/reports/{report}/pdf', [OrganizationReportController::class, 'pdf'])->middleware('throttle:exports')->name('analytics.reports.pdf');

    // The Staff module (Owner-only, see StaffPolicy): a real CRUD resource over the org's
    // `User` records, alongside the invitation flow that creates them.
    Route::resource('staff', StaffController::class)->only(['index', 'show', 'edit', 'update', 'destroy']);
    Route::post('staff/invitations', [StaffInvitationController::class, 'store'])->name('staff.invitations.store');
    Route::delete('staff/invitations/{invitation}', [StaffInvitationController::class, 'destroy'])->name('staff.invitations.destroy');
});

// Step 6.3: the client portal is a real, authenticated session (unlike the token-based
// `/review/:token` and `/client-invite/:token` links above) — every route here is guarded by the
// `portal` middleware, which requires a `Role::ClientReviewer` session and scopes any bound
// `{post}`/`{invoice}` to that user's own `client_id`.
Route::prefix('portal')->name('portal.')->middleware(['auth', 'verified', 'org.member', 'portal'])->group(function () {
    Route::get('/', [PortalDashboardController::class, 'index'])->name('dashboard');
    Route::get('calendar', [PortalCalendarController::class, 'index'])->name('calendar');
    Route::get('company', [PortalClientController::class, 'show'])->name('company');
    Route::get('campaigns', [PortalCampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{campaign}', [PortalCampaignController::class, 'show'])->name('campaigns.show');
    Route::post('campaigns/{campaign}/brief/decide', [PortalCampaignController::class, 'decideBrief'])->name('campaigns.brief.decide');
    Route::get('campaigns/{campaign}/report/pdf', [CampaignResultsController::class, 'portalReportPdf'])->middleware('throttle:exports')->name('campaigns.report.pdf');
    Route::get('campaigns/{campaign}/review', [PortalCampaignController::class, 'review'])->name('campaigns.review');
    Route::post('campaigns/{campaign}/review', [PortalCampaignController::class, 'submitReview'])->name('campaigns.review.submit');
    Route::get('campaigns/{campaign}/brief/pdf', [PortalCampaignController::class, 'briefPdf'])->middleware('throttle:exports')->name('campaigns.brief.pdf');
    Route::post('campaigns/{campaign}/comments', [CampaignCommentController::class, 'store'])->name('campaigns.comments.store');
    Route::get('posts', [PortalPostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PortalPostController::class, 'show'])->name('posts.show');
    Route::post('posts/{post}/decide', [PortalPostController::class, 'decide'])->name('posts.decide');
    Route::post('posts/{post}/comments', [PortalPostController::class, 'comment'])->name('posts.comments.store');
    // Phase 18: the client's grids (only once sent to them; see `EnsureClientPortalAccess`).
    Route::get('grids', [PortalGridController::class, 'index'])->name('grids.index');
    Route::get('grids/{grid}', [PortalGridController::class, 'show'])->name('grids.show');
    Route::post('grids/{grid}/decide', [PortalGridController::class, 'decide'])->name('grids.decide');
    Route::post('grids/{grid}/comments', [PortalGridController::class, 'comment'])->name('grids.comments.store');
    Route::get('invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [PortalInvoiceController::class, 'pdf'])->name('invoices.pdf');
    // Step M-3.3: the client's published monthly reports.
    Route::get('reports', [PortalReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [PortalReportController::class, 'show'])->name('reports.show');
    Route::get('reports/{report}/pdf', [PortalReportController::class, 'pdf'])->middleware('throttle:exports')->name('reports.pdf');
    // Step M-2.8: "Connect your accounts" — the client's own accounts only.
    Route::post('accounts/{socialAccount}/connect', [PortalAccountConnectController::class, 'connect'])->name('accounts.connect');
});

// Phase 15: the platform super admin console — org-less platform staff only (`super_admin`
// middleware; every organization role gets a 403). Mirrors how the portal is grouped above.
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'verified', 'super_admin'])->group(function () {
    Route::get('/', SuperAdminDashboardController::class)->name('dashboard');

    Route::get('organizations', [SuperAdminOrganizationController::class, 'index'])->name('organizations.index');
    Route::get('organizations/create', [SuperAdminOrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [SuperAdminOrganizationController::class, 'store'])->name('organizations.store');
    Route::get('organizations/{organization}', [SuperAdminOrganizationController::class, 'show'])->name('organizations.show');
    Route::put('organizations/{organization}', [SuperAdminOrganizationController::class, 'update'])->name('organizations.update');
    Route::post('organizations/{organization}/suspend', [SuperAdminOrganizationController::class, 'suspend'])->name('organizations.suspend');
    Route::post('organizations/{organization}/reinstate', [SuperAdminOrganizationController::class, 'reinstate'])->name('organizations.reinstate');
    Route::delete('organizations/{organization}', [SuperAdminOrganizationController::class, 'destroy'])->name('organizations.destroy');
    Route::get('organizations/{organization}/analytics', [SuperAdminOrganizationController::class, 'analytics'])->name('organizations.analytics');

    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    Route::resource('reports', ReportController::class);
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->middleware(['throttle:exports', 'password.confirm'])->name('reports.export');
});

// Step 0.14: preview mode mirrors the authenticated app routes above, but scoped to the frozen
// "Preview Studio" fixture org (via the `preview` middleware, which also signs the visitor in as
// that org's owner) and hard-blocked from ever writing — the same middleware rejects every
// non-GET request with a 403 before any controller runs, including these write endpoints, which
// are kept here on purpose so the §12 acceptance test can assert the rejection.
Route::prefix('preview')->name('preview.')->middleware(['preview'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');

    Route::get('home', [HomeController::class, 'index'])->name('needs-attention');

    Route::resource('clients', ClientController::class);
    // Step I-2.4: one route per client page tab (`clients.show` is the Social accounts tab).
    Route::get('clients/{client}/posts', [ClientTabController::class, 'posts'])->name('clients.posts.index');
    Route::get('clients/{client}/media', [ClientTabController::class, 'media'])->name('clients.media');
    Route::get('clients/{client}/campaigns', [ClientTabController::class, 'campaigns'])->name('clients.campaigns.index');
    Route::get('clients/{client}/billing', [ClientTabController::class, 'billing'])->name('clients.billing');
    Route::get('clients/{client}/portal-access', [ClientTabController::class, 'portalAccess'])->name('clients.portal-access');
    Route::get('clients/{client}/ai', [ClientTabController::class, 'ai'])->name('clients.ai.index');
    Route::get('clients/{client}/briefs', [ClientTabController::class, 'briefs'])->name('clients.briefs');

    Route::get('clients/{client}/brand-brain', [BrandBrainController::class, 'edit'])
        ->name('clients.brand-brain.edit');
    Route::put('clients/{client}/brand-brain', [BrandBrainController::class, 'update'])
        ->name('clients.brand-brain.update');
    Route::post('clients/{client}/brand-brain/persona', [BrandBrainController::class, 'uploadPersona'])
        ->name('clients.brand-brain.persona.store');

    Route::post('clients/{client}/invoices', [InvoiceController::class, 'store'])
        ->name('clients.invoices.store');
    Route::get('invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('invoices/export', [InvoiceController::class, 'export'])
        ->middleware('throttle:exports')
        ->name('invoices.export');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])
        ->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->name('invoices.destroy');
    Route::post('invoices/{invoice}/pdf', [InvoiceController::class, 'uploadPdf'])
        ->name('invoices.pdf.store');
    Route::post('invoices/{invoice}/generate-pdf', [InvoiceController::class, 'generatePdf'])
        ->middleware('throttle:exports')
        ->name('invoices.generate-pdf');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');
    Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])
        ->name('invoices.finalize');
    Route::post('invoices/{invoice}/remind', [InvoiceController::class, 'remind'])
        ->name('invoices.remind');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->name('invoices.void');
    Route::post('invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])
        ->name('invoices.duplicate');

    Route::post('invoices/{invoice}/items', [InvoiceItemController::class, 'store'])
        ->name('invoices.items.store');
    Route::put('invoice-items/{invoiceItem}', [InvoiceItemController::class, 'update'])
        ->name('invoice-items.update');
    Route::delete('invoice-items/{invoiceItem}', [InvoiceItemController::class, 'destroy'])
        ->name('invoice-items.destroy');

    Route::post('clients/{client}/social-accounts', [SocialAccountController::class, 'store'])
        ->name('clients.social-accounts.store');
    Route::put('social-accounts/{socialAccount}', [SocialAccountController::class, 'update'])
        ->name('social-accounts.update');
    Route::delete('social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])
        ->name('social-accounts.destroy');
    Route::post('social-accounts/{socialAccount}/connect', [SocialAccountController::class, 'connect'])
        ->name('social-accounts.connect');

    Route::post('clients/{client}/campaigns', [CampaignController::class, 'store'])
        ->name('clients.campaigns.store');
    // Campaigns enhancement: the hub and every workspace tab, read-only like the rest of preview.
    Route::get('campaigns', CampaignIndexController::class)->name('campaigns.index');
    Route::get('campaigns/{campaign}', [CampaignWorkspaceController::class, 'show'])->name('campaigns.show');
    Route::get('campaigns/{campaign}/brief', [CampaignWorkspaceController::class, 'brief'])->name('campaigns.brief');
    Route::get('campaigns/{campaign}/plan', [CampaignWorkspaceController::class, 'plan'])->name('campaigns.plan');
    Route::get('campaigns/{campaign}/board', [CampaignWorkspaceController::class, 'board'])->name('campaigns.board');
    Route::get('campaigns/{campaign}/assets', [CampaignWorkspaceController::class, 'assets'])->name('campaigns.assets');
    Route::get('campaigns/{campaign}/approvals', [CampaignWorkspaceController::class, 'approvals'])->name('campaigns.approvals');
    Route::get('campaigns/{campaign}/results', [CampaignWorkspaceController::class, 'results'])->name('campaigns.results');
    Route::get('campaigns/{campaign}/activity', [CampaignWorkspaceController::class, 'activity'])->name('campaigns.activity');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
        ->name('campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');
    Route::post('campaigns/{campaign}/transition', [CampaignController::class, 'transition'])
        ->name('campaigns.transition');

    // Phase 18: the grid planner, read-only (the writes stay listed so the 403 can be asserted).
    Route::get('grids', GridPlanIndexController::class)->name('grids.index');
    Route::get('clients/{client}/grids', [GridPlanController::class, 'index'])->name('clients.grids.index');
    Route::post('clients/{client}/grids', [GridPlanController::class, 'store'])->name('clients.grids.store');
    Route::get('grids/{grid}/edit', [GridPlanController::class, 'edit'])->name('grids.edit');
    Route::put('grids/{grid}', [GridPlanController::class, 'update'])->name('grids.update');
    Route::post('grids/{grid}/tiles', [GridTileController::class, 'store'])->name('grids.tiles.store');
    Route::post('grids/{grid}/tiles/reorder', [GridTileController::class, 'reorder'])->name('grids.tiles.reorder');
    Route::post('grids/{grid}/transition', [GridWorkflowController::class, 'transition'])->name('grids.transition');
    Route::get('grids/{grid}/schedule', [GridProductionController::class, 'previewSchedule'])->name('grids.schedule.preview');

    Route::get('clients/{client}/assets', [AssetController::class, 'index'])
        ->name('clients.assets.index');
    Route::post('clients/{client}/assets', [AssetController::class, 'store'])
        ->name('clients.assets.store');
    Route::delete('assets/{asset}', [AssetController::class, 'destroy'])
        ->name('assets.destroy');

    Route::post('clients/{client}/posts', [PostController::class, 'store'])
        ->name('clients.posts.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])
        ->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])
        ->name('posts.update');
    Route::post('posts/{post}/transition', [PostController::class, 'transition'])
        ->name('posts.transition');
    Route::post('posts/{post}/reschedule', [PostController::class, 'reschedule'])
        ->name('posts.reschedule');
    Route::post('posts/{post}/comments', [PostController::class, 'comment'])
        ->name('posts.comments.store');
    Route::post('posts/{post}/activity-logs/{activityLog}/resend-review-email', [PostController::class, 'resendReviewEmail'])
        ->name('posts.activity-logs.resend-review-email');
    Route::get('posts/{post}/music-search', [PostController::class, 'musicSearch'])
        ->middleware('throttle:music-search')
        ->name('posts.music-search');

    Route::post('clients/{client}/invitations', [ClientInvitationController::class, 'store'])
        ->name('clients.invitations.store');
    Route::delete('invitations/{invitation}', [ClientInvitationController::class, 'destroy'])
        ->name('invitations.destroy');
    // Phase 17: read-only analytics mirror (exports included — they're GETs).
    Route::get('analytics', [OrganizationAnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/export', [OrganizationAnalyticsController::class, 'export'])->middleware('throttle:exports')->name('analytics.export');
    Route::get('analytics/pdf', [OrganizationAnalyticsController::class, 'pdf'])->middleware('throttle:exports')->name('analytics.pdf');
    Route::get('clients/{client}/analytics', [OrganizationAnalyticsController::class, 'client'])->name('clients.analytics');
    Route::get('clients/{client}/reports', [ClientReportController::class, 'index'])->name('clients.reports.index');
    Route::get('client-reports/{report}', [ClientReportController::class, 'show'])->name('client-reports.show');
    Route::get('analytics/reports', [OrganizationReportController::class, 'index'])->name('analytics.reports.index');
    Route::get('analytics/reports/{report}', [OrganizationReportController::class, 'show'])->name('analytics.reports.show');
    Route::get('analytics/reports/{report}/export', [OrganizationReportController::class, 'export'])->middleware('throttle:exports')->name('analytics.reports.export');
    Route::get('analytics/reports/{report}/pdf', [OrganizationReportController::class, 'pdf'])->middleware('throttle:exports')->name('analytics.reports.pdf');
});

require __DIR__.'/settings.php';
