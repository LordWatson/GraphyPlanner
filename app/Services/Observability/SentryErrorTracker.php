<?php

namespace App\Services\Observability;

use App\Contracts\ErrorTracker;
use App\Support\Observability\TraceContext;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Throwable;

use function Sentry\captureException;
use function Sentry\withScope;

/**
 * Step I-16.1 / M-1.1 — sends reported exceptions to Sentry, tagged with the trace ids from
 * `Context` (the full `Context` also rides along via the SDK's Laravel context integration).
 * Only the user id is sent, never an email or name; `SecretScrubber::beforeSend` strips secrets.
 */
class SentryErrorTracker implements ErrorTracker
{
    public function capture(Throwable $exception): void
    {
        withScope(function (Scope $scope) use ($exception): void {
            $tags = TraceContext::tags();

            $scope->setTags($tags);

            if (isset($tags[TraceContext::USER_ID])) {
                $scope->setUser(UserDataBag::createFromUserIdentifier($tags[TraceContext::USER_ID]));
            }

            captureException($exception);
        });
    }
}
