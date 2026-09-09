<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\BrandBrainController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SocialAccountController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Step 0.13: the client review portal is unauthenticated — access is granted purely by
// possession of a valid `ReviewToken`, never a logged-in session (spec §10/§12).
Route::get('review/{token}', [ReviewController::class, 'show'])->name('review.show');
Route::post('review/{token}', [ReviewController::class, 'decide'])->name('review.decide');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');

    Route::get('home', [HomeController::class, 'index'])->name('needs-attention');

    Route::resource('clients', ClientController::class);

    Route::get('clients/{client}/brand-brain', [BrandBrainController::class, 'edit'])
        ->name('clients.brand-brain.edit');
    Route::put('clients/{client}/brand-brain', [BrandBrainController::class, 'update'])
        ->name('clients.brand-brain.update');

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
});

require __DIR__.'/settings.php';
