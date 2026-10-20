<?php

namespace App\Support\Observability;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Step I-16.1 / M-1.1 — strips secrets from anything that leaves the app for error tracking or
 * alerts: API keys, tokens, passwords, webhook signatures, cookies and auth headers. Matching is
 * by key name, recursively, so a nested `organization.upload_post_key` is caught too.
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
            }
        }

        return $data;
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
     * Sentry `before_send` hook (wired in `config/sentry.php`): scrubs the request (body, query,
     * headers, cookies), extra data and every context before the event is sent.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $event->setRequest(self::scrub($event->getRequest()));
        $event->setExtra(self::scrub($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrub($context));
        }

        return $event;
    }
}
