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

    /**
     * Audit finding 12 step 4 — work somebody is sitting and waiting for: a requested data
     * export, and anything else a person clicked a button for. Its own queue and its own
     * supervisor, so a backed-up engagement sweep on `reports` never makes that person wait.
     */
    public const EXPORTS = 'exports';

    /**
     * Step W-2.2 — WhatsApp sends. Its own queue because each one is an HTTP round trip to
     * Twilio, and a slow one must not sit in front of the bell notification or the email that
     * carry the same news.
     */
    public const WHATSAPP = 'whatsapp';

    /** Report generation and the background vendor sweeps that feed it. */
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
            self::WHATSAPP,
            self::AI,
            (string) config('ai.images.queue', 'ai-images'),
            self::EXPORTS,
            self::REPORTS,
            self::DEFAULT,
        ]));
    }
}
