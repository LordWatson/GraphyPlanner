<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Step I-16.1 / M-1.1 — the identifiers we put in Laravel's `Context` so one publish can be
 * followed request → job → webhook. `Context` adds them to every log line, carries them into
 * queued jobs automatically, and the Sentry SDK attaches them to every event; `tags()` picks the
 * ones error tracking indexes for search.
 */
final class TraceContext
{
    public const CORRELATION_ID = 'correlation_id';

    public const USER_ID = 'user_id';

    public const ORG_ID = 'org_id';

    public const POST_ID = 'post_id';

    public const POST_TARGET_ID = 'post_target_id';

    /**
     * The correlation id of the request/job that first sent a target to the vendor (stamped on
     * `post_targets.publish_correlation_id`), added when its webhook arrives.
     */
    public const PUBLISH_CORRELATION_ID = 'publish_correlation_id';

    /**
     * Keys copied onto error-tracking events as searchable tags.
     *
     * @var list<string>
     */
    public const TAG_KEYS = [
        self::CORRELATION_ID, self::USER_ID, self::ORG_ID, self::POST_ID, self::POST_TARGET_ID,
        self::PUBLISH_CORRELATION_ID,
    ];

    /**
     * A well-formed incoming id is reused (so a proxy's id lines up with ours); anything else is
     * replaced, so a client can't inject arbitrary text into our logs.
     */
    public static function isValidId(?string $id): bool
    {
        return $id !== null && preg_match('/\A[A-Za-z0-9._-]{8,64}\z/', $id) === 1;
    }

    public static function correlationId(): ?string
    {
        $id = Context::get(self::CORRELATION_ID);

        return is_string($id) ? $id : null;
    }

    /**
     * Make sure there's a correlation id (scheduler runs and jobs dispatched outside a request
     * start without one) and return it.
     */
    public static function ensureCorrelationId(): string
    {
        $id = self::correlationId();

        if ($id === null) {
            $id = (string) Str::uuid();
            Context::add(self::CORRELATION_ID, $id);
        }

        return $id;
    }

    /**
     * @return array<string, string>
     */
    public static function tags(): array
    {
        $tags = [];

        foreach (self::TAG_KEYS as $key) {
            $value = Context::get($key);

            if (is_scalar($value) && $value !== '') {
                $tags[$key] = (string) $value;
            }
        }

        return $tags;
    }
}
