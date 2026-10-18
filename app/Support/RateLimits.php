<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step I-1.7 — the app's named rate limiters, registered from `AppServiceProvider::boot()` and
 * applied with `throttle:<name>` in the route files.
 *
 * | Limiter        | Scope                         | Limit                    | Used by                              |
 * |----------------|-------------------------------|--------------------------|--------------------------------------|
 * | `ai`           | per user + per organization   | 10/min user, 60/min org  | AI trends, ideas, captions           |
 * | `ai-images`    | per user + per organization   | 6/min user, 30/min org   | AI Image Studio generate/refine      |
 * | `music-search` | per user                      | 30/min                   | editor music search                  |
 * | `exports`      | per user                      | 10/min                   | CSV/PDF exports, invoice PDF build   |
 * | `public-token` | per IP + token, and per IP    | 20/min token, 60/min IP  | review link, client/staff invites    |
 * | `webhooks`     | per IP                        | 120/min                  | Upload-Post webhook                  |
 *
 * Every limiter answers with the same structured 429 (`message` + `retry_after`), which JSON
 * callers show as-is; Inertia visits get the same message as an error toast instead.
 */
final class RateLimits
{
    public static function register(): void
    {
        RateLimiter::for('ai', fn (Request $request) => self::perUserAndOrg($request, 'ai', 10, 60, 'AI'));

        RateLimiter::for('ai-images', fn (Request $request) => self::perUserAndOrg($request, 'ai-images', 6, 30, 'image generation'));

        RateLimiter::for('music-search', fn (Request $request) => self::limit(
            Limit::perMinute(30)->by('music-search:'.self::actor($request)),
            'music search',
        ));

        RateLimiter::for('exports', fn (Request $request) => self::limit(
            Limit::perMinute(10)->by('exports:'.self::actor($request)),
            'export',
        ));

        RateLimiter::for('public-token', fn (Request $request) => [
            self::limit(
                Limit::perMinute(20)->by('public-token:'.$request->ip().':'.hash('sha256', (string) $request->route('token'))),
                'link',
            ),
            // A cap across tokens too, so one address can't walk through guesses.
            self::limit(Limit::perMinute(60)->by('public-token-ip:'.$request->ip()), 'link'),
        ]);

        RateLimiter::for('webhooks', fn (Request $request) => self::limit(
            Limit::perMinute(120)->by('webhooks:'.$request->ip()),
            'webhook',
        ));
    }

    /**
     * The structured 429 every limiter returns.
     *
     * @param  array<string, string|int>  $headers
     */
    public static function tooManyAttempts(Request $request, array $headers, string $label): Response
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = __('You’ve hit the :label limit for this minute — try again in :seconds s.', [
            'label' => $label,
            'seconds' => $retryAfter,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $retryAfter], 429, $headers);
        }

        if ($request->header('X-Inertia')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(303)->withHeaders($headers);
        }

        return response($message, 429, $headers);
    }

    /**
     * @return list<Limit>
     */
    private static function perUserAndOrg(Request $request, string $name, int $perUser, int $perOrg, string $label): array
    {
        $user = $request->user();

        return [
            self::limit(Limit::perMinute($perUser)->by("{$name}:user:".self::actor($request)), $label),
            self::limit(Limit::perMinute($perOrg)->by("{$name}:org:".($user->org_id ?? 'guest-'.$request->ip())), $label),
        ];
    }

    private static function limit(Limit $limit, string $label): Limit
    {
        return $limit->response(fn (Request $request, array $headers) => self::tooManyAttempts($request, $headers, $label));
    }

    private static function actor(Request $request): string
    {
        return (string) ($request->user()->id ?? $request->ip());
    }
}
