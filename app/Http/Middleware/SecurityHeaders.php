<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step I-16.2 / M-1.2 — global middleware: Content-Security-Policy (nonce-based scripts),
 * HSTS on HTTPS, clickjacking protection, `nosniff`, Referrer-Policy and Permissions-Policy on
 * every response. See `config/security.php`.
 *
 * Framing is `self`, not `none`: the invoice and brand persona pages embed our own PDFs.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Set before the view renders, so `@vite`, `@viteReactRefresh` and the inline theme
        // script all get the same nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', (string) config('security.referrer_policy'));
        $headers->set('Permissions-Policy', (string) config('security.permissions_policy'));

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', $this->hsts());
        }

        if (config('security.csp.enabled') && ! $request->is(...(array) config('security.csp.except'))) {
            $name = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers->set($name, $this->contentSecurityPolicy($nonce, $request->isSecure()));
        }

        return $response;
    }

    public function contentSecurityPolicy(string $nonce, bool $secure): string
    {
        // The Vite dev server (`npm run dev`) serves scripts, styles and HMR from its own origin.
        $dev = Vite::isRunningHot() ? $this->devServerOrigins() : [];
        $devSockets = array_map(fn (string $origin) => (string) preg_replace('/^http/', 'ws', $origin), $dev);

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            'font-src' => ["'self'", 'data:', ...$dev],
            // Signed media URLs, vendor avatars and AI images can come from other HTTPS hosts.
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...$dev],
            'media-src' => ["'self'", 'blob:', 'https:'],
            'connect-src' => ["'self'", ...$dev, ...$devSockets],
            'frame-src' => ["'self'", 'blob:', 'https:'],
            'frame-ancestors' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'", 'https:'],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', array_unique($sources)))
            ->values()
            ->all();

        if ($secure) {
            $policy[] = 'upgrade-insecure-requests';
        }

        if ($uri = config('security.csp.report_uri')) {
            $policy[] = 'report-uri '.$uri;
        }

        return implode('; ', $policy);
    }

    private function hsts(): string
    {
        $value = 'max-age='.(int) config('security.hsts.max_age');

        return config('security.hsts.include_subdomains') ? $value.'; includeSubDomains' : $value;
    }

    /**
     * The Vite dev server's origin(s) from the hot file — local development only.
     *
     * CSP host sources can't be IPv6 literals: browsers ignore `http://[::1]:5173`, which blocks
     * every module Vite serves. For an IPv6 dev host we fall back to allowing the scheme (this
     * only ever applies while `npm run dev` is running).
     *
     * @return list<string>
     */
    public function devServerOrigins(): array
    {
        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        if (str_contains($parts['host'], ':')) {
            return [$parts['scheme'].':'];
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = $parts['scheme'].'://'.$parts['host'].$port;

        // The browser may load the page from `localhost` while Vite wrote `127.0.0.1` (or the reverse).
        return array_values(array_unique([$origin, $parts['scheme'].'://localhost'.$port, $parts['scheme'].'://127.0.0.1'.$port]));
    }
}
