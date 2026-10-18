<?php

use App\Http\Middleware\EnsureCampaignFeature;
use App\Http\Middleware\EnsureClientPortalAccess;
use App\Http\Middleware\EnsureOrganizationNotSuspended;
use App\Http\Middleware\EnsurePreviewMode;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectClientReviewersToPortal;
use App\Http\Middleware\RejectPreviewUserOutsidePreview;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Step 1.5: the Upload-Post webhook is a server-to-server call with no session/CSRF
        // token — it's verified instead via its own signature header (see
        // `UploadPostWebhookController`).
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->web(append: [
            // Step I-1.1: the preview owner is never a real session outside `/preview/*`.
            RejectPreviewUserOutsidePreview::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Step 15.3: after HandleInertiaRequests so the "suspended" page gets the shared props.
            EnsureOrganizationNotSuspended::class,
        ]);

        $middleware->alias([
            'preview' => EnsurePreviewMode::class,
            'portal' => EnsureClientPortalAccess::class,
            'portal.redirect' => RedirectClientReviewersToPortal::class,
            // Step 15.1: the platform super admin console, and the org-workspace guard that
            // sends org-less super admins there instead.
            'super_admin' => EnsureUserIsSuperAdmin::class,
            'org.member' => EnsureUserHasOrganization::class,
            // Campaigns enhancement C-9.4: gated campaign features.
            'campaign.feature' => EnsureCampaignFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
