<?php

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Step I-16.1 / M-1.1 — strips secrets from anything that leaves the app for error tracking or
 * alerts: API keys, tokens, passwords, webhook signatures, cookies and auth headers. Matching is
 * by key name, recursively, so a nested `organization.upload_post_key` is caught too.
 *
 * Step S-6.4: a public link carries its secret in the path (`/story/{token}`, `/review/{token}`,
 * `/kit/{token}`), where no key name gives it away. So every string value also has any long
 * token-shaped path segment replaced ({@see self::scrubUrl()}), and the Sentry hook does the same
 * to the transaction name and the breadcrumbs.
 */
final class SecretScrubber
{
    public const FILTERED = '[Filtered]';

    /**
     * Key fragments that mark a value as secret (lower-cased, substring match).
     */
    private const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', '_key', 'authorization',
        'cookie', 'signature', 'session', 'credential', 'xsrf', 'csrf', 'otp', 'recovery_code',
    ];

    /**
     * A whole path segment of 32 or more letters and digits: the shape of every link token the app
     * issues (`Str::random(40|64)`). Deliberately narrow — a UUID has dashes, an id is short, and
     * a file name has a dot — so ordinary URLs and paths come through untouched.
     */
    private const TOKEN_SEGMENT = '~(?<=/)[A-Za-z0-9]{32,}(?=[/?#\s"\']|$)~';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::FILTERED;
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            } elseif (is_string($value)) {
                $data[$key] = self::scrubUrl($value);
            }
        }

        return $data;
    }

    /**
     * Replace token-shaped path segments in a URL, or in any text that contains one:
     * `https://app.test/story/Ab3…9z` becomes `https://app.test/story/[Filtered]`.
     */
    public static function scrubUrl(string $value): string
    {
        if (! str_contains($value, '/')) {
            return $value;
        }

        return preg_replace(self::TOKEN_SEGMENT, self::FILTERED, $value) ?? $value;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sentry `before_send` hook (wired in `config/sentry.php`): scrubs the request (URL, body,
     * query, headers, cookies), extra data, every context, the transaction name and the
     * breadcrumbs before the event is sent.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $event->setRequest(self::scrub($event->getRequest()));
        $event->setExtra(self::scrub($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrub($context));
        }

        $transaction = $event->getTransaction();

        if ($transaction !== null) {
            $event->setTransaction(self::scrubUrl($transaction));
        }

        $event->setBreadcrumb(array_map(self::scrubBreadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    /**
     * A breadcrumb is a log line or an HTTP call made just before the error: its message and its
     * data (`url`, …) are scrubbed like everything else.
     */
    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrubUrl($message));
        }

        foreach (self::scrub($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }
}
