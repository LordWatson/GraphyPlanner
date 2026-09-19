<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandBrainController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientInvitationAcceptController;
use App\Http\Controllers\ClientInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Portal\PortalClientController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalPostController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Step 0.13: the client review portal is unauthenticated — access is granted purely by
// possession of a valid `ReviewToken`, never a logged-in session (spec §10/§12).
Route::get('review/{token}', [ReviewController::class, 'show'])->name('review.show');
Route::post('review/{token}', [ReviewController::class, 'decide'])->name('review.decide');

// Step 6.2: accepting a client portal invitation is unauthenticated — access is granted purely
// by possession of a valid, unexpired, unrevoked, unaccepted `ClientInvitation` token, mirroring
// the review portal above.
Route::get('client-invite/{token}', [ClientInvitationAcceptController::class, 'show'])->name('client-invite.show');
Route::post('client-invite/{token}', [ClientInvitationAcceptController::class, 'store'])->name('client-invite.store');

// Step 1.3: Upload-Post redirects the browser back here once the user finishes the hosted
// connect flow, so this must be reachable without a session, just like the review portal above.
Route::get('social-accounts/callback', [SocialAccountController::class, 'callback'])->name('social-accounts.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    // Reachable by every logged-in role (including Role::ClientReviewer/portal users), unlike
    // the internal-only routes below — the notification bell is shared across both layouts.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

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
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');

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
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');

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
    Route::post('posts/{post}/comments', [PostController::class, 'comment'])
        ->name('posts.comments.store');
    Route::post('posts/{post}/activity-logs/{activityLog}/resend-review-email', [PostController::class, 'resendReviewEmail'])
        ->name('posts.activity-logs.resend-review-email');

    // Step 6.1: client portal invitations (Owner/Strategist only, see ClientInvitationPolicy).
    Route::post('clients/{client}/invitations', [ClientInvitationController::class, 'store'])
        ->name('clients.invitations.store');
    Route::delete('invitations/{invitation}', [ClientInvitationController::class, 'destroy'])
        ->name('invitations.destroy');
});

// Step 6.3: the client portal is a real, authenticated session (unlike the token-based
// `/review/:token` and `/client-invite/:token` links above) — every route here is guarded by the
// `portal` middleware, which requires a `Role::ClientReviewer` session and scopes any bound
// `{post}`/`{invoice}` to that user's own `client_id`.
Route::prefix('portal')->name('portal.')->middleware(['auth', 'verified', 'portal'])->group(function () {
    Route::get('/', [PortalDashboardController::class, 'index'])->name('dashboard');
    Route::get('company', [PortalClientController::class, 'show'])->name('company');
    Route::get('posts', [PortalPostController::class, 'index'])->name('posts.index');
    Route::get('posts/{post}', [PortalPostController::class, 'show'])->name('posts.show');
    Route::post('posts/{post}/decide', [PortalPostController::class, 'decide'])->name('posts.decide');
    Route::post('posts/{post}/comments', [PortalPostController::class, 'comment'])->name('posts.comments.store');
    Route::get('invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
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
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send');
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');

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
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
        ->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
        ->name('campaigns.destroy');

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
    Route::post('posts/{post}/comments', [PostController::class, 'comment'])
        ->name('posts.comments.store');
    Route::post('posts/{post}/activity-logs/{activityLog}/resend-review-email', [PostController::class, 'resendReviewEmail'])
        ->name('posts.activity-logs.resend-review-email');

    Route::post('clients/{client}/invitations', [ClientInvitationController::class, 'store'])
        ->name('clients.invitations.store');
    Route::delete('invitations/{invitation}', [ClientInvitationController::class, 'destroy'])
        ->name('invitations.destroy');
});

require __DIR__.'/settings.php';
