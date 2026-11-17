<?php

namespace App\Models\Scopes;

/**
 * Lets one piece of code run without the two access scopes (`OrganizationScope`,
 * `ClientAccessScope`), whoever happens to be signed in.
 *
 * Both scopes read the session: they limit every query to the signed-in member's organization
 * and clients. That is right everywhere a member is *using* the app, and wrong on a route whose
 * authorisation is a link rather than a login. A Story handoff link is opened by whoever was sent
 * it — and if that person's browser is signed in to a different organization (a client with their
 * own workspace, a freelancer between agencies), the pipeline the link drives would silently find
 * nothing: the channel published but its post never resolved, a failure never notified.
 *
 * `suspended()` is the answer for those routes: inside the callback the scopes apply to nobody,
 * exactly as they do for a queue worker or a scheduled command, so the same code path behaves the
 * same for a guest, a member and a stranger. It nests, and always restores.
 *
 * Use it only where the caller has already been authorised by something other than the session
 * (a token that resolved to one record). It is not a way around a policy.
 */
final class TenantScopes
{
    private static int $suspended = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function suspended(callable $callback): mixed
    {
        self::$suspended++;

        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    public static function areSuspended(): bool
    {
        return self::$suspended > 0;
    }
}
