<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiImageController;
use App\Http\Controllers\Analytics\AnalyticsController as OrganizationAnalyticsController;
use App\Http\Controllers\Analytics\ReportController as OrganizationReportController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\FacebookInstagramConnectController;
use App\Http\Controllers\BrandBrainController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientInvitationAcceptController;
use App\Http\Controllers\ClientInvitationController;
use App\Http\Controllers\DailyBriefController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organizations\RegistrationController;
use App\Http\Controllers\Portal\PortalCalendarController;
use App\Http\Controllers\Portal\PortalCampaignController;
use App\Http\Controllers\Portal\PortalClientController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalPostController;
use App\Http\Controllers\PostController;
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
Route::get('review/{token}', [ReviewController::class, 'show'])->name('review.show');
Route::post('review/{token}', [ReviewController::class, 'decide'])->name('review.decide');

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

// Step 1.3: Upload-Post redirects the browser back here once the user finishes the hosted
// connect flow, so this must be reachable without a session, just like the review portal above.
Route::get('social-accounts/callback', [SocialAccountController::class, 'callback'])->name('social-accounts.callback');

// Step 1.5: Upload-Post posts its publish-outcome webhook here — no session/CSRF token is
// available from a server-to-server vendor call, so this is public (verified instead via the
// `X-Upload-Post-Signature` header, see `UploadPostWebhookController`) and excluded from CSRF
// verification (see `bootstrap/app.php`).
Route::post('webhooks/upload-post', [UploadPostWebhookController::class, 'handle'])->name('webhooks.upload-post');

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
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
        ->name('campaigns.show');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
        ->name('campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');

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
    Route::post('posts/{post}/transition', [PostController::class, 'transition'])
        ->name('posts.transition');
    Route::post('posts/{post}/reschedule', [PostController::class, 'reschedule'])
        ->name('posts.reschedule');
    Route::post('posts/{post}/comments', [PostController::class, 'comment'])
        ->name('posts.comments.store');
    Route::post('posts/{post}/activity-logs/{activityLog}/resend-review-email', [PostController::class, 'resendReviewEmail'])
        ->name('posts.activity-logs.resend-review-email');
    Route::get('posts/{post}/music-search', [PostController::class, 'musicSearch'])
        ->name('posts.music-search');

    // Step 2.4: AI-assisted trends/ideas (client dashboard) and caption suggestions (post
    // editor) — see AiController, gated behind config('ai.enabled') and Owner/Strategist.
    Route::get('clients/{client}/ai/trends', [AiController::class, 'trends'])
        ->name('clients.ai.trends');
    Route::get('clients/{client}/ai/ideas', [AiController::class, 'ideas'])
        ->name('clients.ai.ideas');
    Route::get('posts/{post}/ai/captions', [AiController::class, 'captions'])
        ->name('posts.ai.captions');

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
        ->name('ai-post-ideas.images.store');
    Route::post('clients/{client}/ai/images', [AiImageController::class, 'generate'])
        ->name('clients.ai.images.store');
    Route::get('ai-image-generations/{generation}', [AiImageController::class, 'show'])
        ->name('ai-image-generations.show');
    Route::post('ai-image-generations/{generation}/use', [AiImageController::class, 'useInPost'])
        ->name('ai-image-generations.use');
    Route::post('ai-image-variants/{variant}/refine', [AiImageController::class, 'refine'])
        ->name('ai-image-variants.refine');
    Route::post('ai-image-variants/{variant}/regenerate', [AiImageController::class, 'regenerate'])
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
    Route::get('analytics/export', [OrganizationAnalyticsController::class, 'export'])->name('analytics.export');
    Route::get('analytics/pdf', [OrganizationAnalyticsController::class, 'pdf'])->name('analytics.pdf');
    Route::get('clients/{client}/analytics', [OrganizationAnalyticsController::class, 'client'])->name('clients.analytics');
    Route::resource('analytics/reports', OrganizationReportController::class)
        ->names('analytics.reports')
        ->parameters(['reports' => 'report']);
    Route::get('analytics/reports/{report}/export', [OrganizationReportController::class, 'export'])->name('analytics.reports.export');
    Route::get('analytics/reports/{report}/pdf', [OrganizationReportController::class, 'pdf'])->name('analytics.reports.pdf');

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
    Route::get('posts', [PortalPostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PortalPostController::class, 'show'])->name('posts.show');
    Route::post('posts/{post}/decide', [PortalPostController::class, 'decide'])->name('posts.decide');
    Route::post('posts/{post}/comments', [PortalPostController::class, 'comment'])->name('posts.comments.store');
    Route::get('invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
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
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
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
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
        ->name('campaigns.show');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
        ->name('campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');

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
        ->name('posts.music-search');

    Route::post('clients/{client}/invitations', [ClientInvitationController::class, 'store'])
        ->name('clients.invitations.store');
    Route::delete('invitations/{invitation}', [ClientInvitationController::class, 'destroy'])
        ->name('invitations.destroy');
    // Phase 17: read-only analytics mirror (exports included — they're GETs).
    Route::get('analytics', [OrganizationAnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/export', [OrganizationAnalyticsController::class, 'export'])->name('analytics.export');
    Route::get('analytics/pdf', [OrganizationAnalyticsController::class, 'pdf'])->name('analytics.pdf');
    Route::get('clients/{client}/analytics', [OrganizationAnalyticsController::class, 'client'])->name('clients.analytics');
    Route::get('analytics/reports', [OrganizationReportController::class, 'index'])->name('analytics.reports.index');
    Route::get('analytics/reports/{report}', [OrganizationReportController::class, 'show'])->name('analytics.reports.show');
    Route::get('analytics/reports/{report}/export', [OrganizationReportController::class, 'export'])->name('analytics.reports.export');
    Route::get('analytics/reports/{report}/pdf', [OrganizationReportController::class, 'pdf'])->name('analytics.reports.pdf');
});

require __DIR__.'/settings.php';
