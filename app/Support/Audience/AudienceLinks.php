<?php

namespace App\Support\Audience;

use App\Models\AudienceContact;
use Illuminate\Support\Facades\URL;

/**
 * Step GTM-2.1 — the two links a contact owns. Both are signed routes, so there is no token column
 * to store or leak, and the signature is the whole authorisation.
 *
 * The unsubscribe link **never expires**: every email we send carries one, and an email from six
 * months ago must still be able to stop the next one. The confirmation link does expire — an
 * unconfirmed address a month later is somebody who didn't want it (or a typo'd stranger).
 */
final class AudienceLinks
{
    public const CONFIRM_TTL_DAYS = 30;

    public static function confirm(AudienceContact $contact): string
    {
        return URL::temporarySignedRoute(
            'audience.confirm',
            now()->addDays(self::CONFIRM_TTL_DAYS),
            ['contact' => $contact->id],
        );
    }

    public static function unsubscribe(AudienceContact $contact): string
    {
        return URL::signedRoute('audience.unsubscribe', ['contact' => $contact->id]);
    }
}
