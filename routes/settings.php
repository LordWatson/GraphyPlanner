<?php

use App\Http\Controllers\Settings\AiUsageController;
use App\Http\Controllers\Settings\OrganizationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TaxRateController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

});

// Organization-level settings need an organization: `org.member` (Step 15.1) sends org-less
// super admins to `/super-admin` (they keep profile/security/appearance above).
Route::middleware(['auth', 'verified', 'org.member'])->group(function () {
    // Step I-16.2 / M-1.2: this page holds the org's API keys and webhook secret, so viewing and
    // changing it re-checks the password (`password.confirm`, 3 h window).
    Route::get('settings/organization', [OrganizationController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('organization.edit');
    Route::put('settings/organization', [OrganizationController::class, 'update'])
        ->middleware(RequirePassword::class)
        ->name('organization.update');
    // Step I-1.6: "Test connection" for the stored Upload-Post key.
    Route::post('settings/organization/test-upload-post', [OrganizationController::class, 'testUploadPost'])
        ->middleware('throttle:6,1')
        ->name('organization.test-upload-post');

    // Step 16.11: Owner-only AI usage (images this month vs quota, estimated cost).
    Route::get('settings/ai-usage', [AiUsageController::class, 'edit'])->name('ai-usage.edit');
    Route::put('settings/ai-usage', [AiUsageController::class, 'update'])->name('ai-usage.update');

    // Step M-4.1: the organization's tax-rate list (Owner-only, via TaxRatePolicy). The settings
    // page has already confirmed the password, so these mutations aren't re-gated.
    Route::post('settings/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::put('settings/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::post('settings/tax-rates/{taxRate}/archive', [TaxRateController::class, 'archive'])->name('tax-rates.archive');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
