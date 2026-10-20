<?php

namespace App\Support;

/**
 * Step I-2.8 — the named Redis queues. Each has its own Horizon supervisor (and so its own
 * workers, timeout and retry profile) in `config/horizon.php`, so a burst of AI or report work can
 * never sit in front of a post that's due to publish.
 */
final class Queues
{
    /** `PublishPostJob` / `PublishPostTargetJob` — highest priority, dedicated workers. */
    public const PUBLISHING = 'publishing';

    /** Reserved for vendor webhook processing once it moves off the request (Step I-16). */
    public const WEBHOOKS = 'webhooks';

    /** Database (bell) notifications. */
    public const NOTIFICATIONS = 'notifications';

    /** Every queued mailable and mail-channel notification. */
    public const MAIL = 'mail';

    /** Text AI work; AI Image Studio jobs keep their own `config('ai.images.queue')`. */
    public const AI = 'ai';

    /** Report generation and delivery. */
    public const REPORTS = 'reports';

    public const DEFAULT = 'default';

    /**
     * Every queue a job can land on, highest priority first — what a single worker has to listen
     * to when Horizon isn't running (`php artisan dev`, a database-queue box). A worker started
     * without `--queue` only works `default`, so publishes, mail and notifications would sit
     * unprocessed with no error anywhere.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([
            self::PUBLISHING,
            self::WEBHOOKS,
            self::NOTIFICATIONS,
            self::MAIL,
            self::AI,
            (string) config('ai.images.queue', 'ai-images'),
            self::REPORTS,
            self::DEFAULT,
        ]));
    }
}
