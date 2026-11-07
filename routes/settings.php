<?php

use App\Http\Controllers\Settings\AiUsageController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\McpConnectionController;
use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Controllers\Settings\OrganizationController;
use App\Http\Controllers\Settings\PlanController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TaxRateController;
use App\Http\Controllers\Settings\WorkspaceController;
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

    // Step M-6.7 (I-12.1): per-user notification preferences (in-app + daily digest, per category).
    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

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
    // Step GTM-3.1: the white-label logo, on its own endpoint so the settings PUT stays JSON.
    Route::post('settings/organization/logo', [OrganizationController::class, 'uploadLogo'])
        ->middleware(RequirePassword::class)
        ->name('organization.logo.store');
    Route::delete('settings/organization/logo', [OrganizationController::class, 'removeLogo'])
        ->middleware(RequirePassword::class)
        ->name('organization.logo.destroy');
    // Audit log (Owner only, via the request's `update` check). Who did what, when — the one
    // screen a security questionnaire asks for. The export re-checks the password and shares the
    // export throttle, like the analytics and invoice exports: it is the whole org's activity in
    // one file.
    Route::get('settings/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    Route::get('settings/audit-log/export', [AuditLogController::class, 'export'])
        ->middleware(['throttle:exports', RequirePassword::class])
        ->name('audit-log.export');

    // Data export (Owner only): "download everything you hold for us". Requesting one is cheap;
    // the download re-checks the password because the file is the whole organisation's record, and
    // shares the export throttle so a leaked session cannot pull it repeatedly.
    Route::get('settings/data-export', [DataExportController::class, 'index'])->name('data-export.index');
    Route::post('settings/data-export', [DataExportController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('data-export.store');
    Route::get('settings/data-export/{dataExport}/download', [DataExportController::class, 'download'])
        ->middleware(['throttle:exports', RequirePassword::class])
        ->name('data-export.download');

    // Step I-1.6: "Test connection" for the stored Upload-Post key.
    Route::post('settings/organization/test-upload-post', [OrganizationController::class, 'testUploadPost'])
        ->middleware('throttle:6,1')
        ->name('organization.test-upload-post');

    // Posts a message to the org's team-chat channel so the owner can watch it arrive. Throttled
    // like the connection test above — it makes an outbound call on request.
    Route::post('settings/organization/test-chat', [OrganizationController::class, 'testChatWebhook'])
        ->middleware('throttle:6,1')
        ->name('organization.test-chat');

    // Removing the webhook throws away a stored credential, so it re-checks the password like the
    // logo delete and the settings PUT. Sending a test message does not — it changes nothing.
    Route::delete('settings/organization/chat', [OrganizationController::class, 'removeChatWebhook'])
        ->middleware(RequirePassword::class)
        ->name('organization.chat.destroy');

    // Step CG-2.5 / B-9.3: the Owner-only plan page — current plan, usage meters, Stripe Checkout
    // (upgrade) and the Customer Portal (manage billing). Billing ships dark behind the super-admin
    // switch; the page renders either way and the controller guards every Stripe call.
    Route::get('settings/plan', [PlanController::class, 'edit'])->name('plan.edit');
    Route::post('settings/plan/checkout', [PlanController::class, 'checkout'])
        ->middleware('throttle:12,1')
        ->name('plan.checkout');
    Route::get('settings/plan/portal', [PlanController::class, 'portal'])->name('plan.portal');
    Route::get('settings/plan/success', [PlanController::class, 'success'])->name('plan.success');

    // Step 16.11: Owner-only AI usage (images this month vs quota, estimated cost).
    Route::get('settings/ai-usage', [AiUsageController::class, 'edit'])->name('ai-usage.edit');
    Route::put('settings/ai-usage', [AiUsageController::class, 'update'])->name('ai-usage.update');
    // Step M-7.1: the per-org AI enable toggle + monthly token budget.
    Route::put('settings/ai-settings', [AiUsageController::class, 'updateSettings'])->name('ai-settings.update');

    // Step MCP-6.1: the Owner-only "AI connections" page — MCP bearer tokens, scopes, usage/audit
    // and the per-org master switch. It issues secrets, so viewing it re-confirms the password
    // (like the organization page); the token mutations below inherit that confirmed window.
    Route::get('settings/ai-connections', [McpConnectionController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('mcp-connections.edit');
    Route::post('settings/ai-connections/tokens', [McpConnectionController::class, 'store'])->name('mcp-connections.store');
    Route::delete('settings/ai-connections/tokens/{mcpToken}', [McpConnectionController::class, 'destroy'])->name('mcp-connections.destroy');
    Route::put('settings/ai-connections', [McpConnectionController::class, 'updateSettings'])->name('mcp-connections.update');

    // Step M-4.1: the organization's tax-rate list (Owner-only, via TaxRatePolicy). The settings
    // page has already confirmed the password, so these mutations aren't re-gated.
    Route::post('settings/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::put('settings/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::post('settings/tax-rates/{taxRate}/archive', [TaxRateController::class, 'archive'])->name('tax-rates.archive');
});

// Step B-3.3: the business workspace's own settings (e.g. "require post review"). Business-only, so
// an agency 404s here; the Owner check is in the request/policy.
Route::middleware(['auth', 'verified', 'org.member', 'workspace:business'])->group(function () {
    Route::get('settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace-settings.edit');
    Route::put('settings/workspace', [WorkspaceController::class, 'update'])->name('workspace-settings.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
