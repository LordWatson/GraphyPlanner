<?php

use App\Contracts\ErrorTracker;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureCampaignFeature;
use App\Http\Middleware\EnsureClientPortalAccess;
use App\Http\Middleware\EnsureOrganizationNotSuspended;
use App\Http\Middleware\EnsurePreviewMode;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\EnsureWorkspaceType;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectClientReviewersToPortal;
use App\Http\Middleware\RejectPreviewUserOutsidePreview;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ShareRequestContext;
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
        // Step I-16.1 / M-1.1: first in line, so every log line and error from here on carries
        // the request's correlation id.
        $middleware->prepend(AssignCorrelationId::class);

        // Step I-16.2 / M-1.2: CSP (nonce scripts), HSTS, framing, nosniff, referrer and
        // permissions policies on every response — global, so webhooks, files and errors get them.
        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Step 1.5: the Upload-Post webhook is a server-to-server call with no session/CSRF
        // token — it's verified instead via its own signature header (see
        // `UploadPostWebhookController`).
        // Step B-7.3: the Web Share Target POST is initiated by the OS share sheet, so it can't carry
        // a CSRF token. It stays safe behind `auth`/`verified`/`workspace:business` + media
        // validation, and only ingests a file (no state change beyond creating an asset).
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'posts/quick/share']);

        $middleware->web(append: [
            // Step I-1.1: the preview owner is never a real session outside `/preview/*`.
            RejectPreviewUserOutsidePreview::class,
            // Step I-16.1 / M-1.1: user + org ids into `Context` (logs, jobs, error tracking).
            ShareRequestContext::class,
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
            // Step B-1.4: keep each organization type inside its own shell.
            'workspace' => EnsureWorkspaceType::class,
            // Campaigns enhancement C-9.4: gated campaign features.
            'campaign.feature' => EnsureCampaignFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Step I-16.1 / M-1.1: every reported exception goes to error tracking (Sentry when
        // configured), tagged from `Context`. The same exception object is only reported once,
        // so the failed-publishing-job listener and the queue worker don't double-send it.
        $exceptions->dontReportDuplicates();
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorTracker::class)->capture($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
