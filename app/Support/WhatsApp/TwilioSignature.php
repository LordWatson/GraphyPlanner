<?php

namespace App\Support\WhatsApp;

/**
 * Step W-3.1 — verifies Twilio's `X-Twilio-Signature`, which is the only authorisation the webhook
 * has.
 *
 * Twilio's scheme (twilio.com/docs/usage/security, read 8 Nov 2026): take the full request URL,
 * append every POST parameter as name then value in case-sensitive name order with no separators,
 * HMAC-SHA1 it with the account's auth token, and base64 the result.
 *
 * Hand-rolled rather than pulled from `twilio/sdk`, like every other vendor signature in this app
 * (`UploadPostWebhookController`, `ResendWebhookController`) — it is six lines and adding an SDK
 * for them would be the larger change. The comparison is `hash_equals`, so a wrong signature takes
 * the same time as a right one.
 *
 * Two things break it, and both are handled where the route is declared: Laravel's `TrimStrings`
 * and `ConvertEmptyStringsToNull` edit the parameters before they get here, and a URL that does
 * not match what Twilio dialled (a proxy rewriting the scheme or host) hashes differently.
 */
final class TwilioSignature
{
    /**
     * @param  array<string, mixed>  $params  the POST parameters, exactly as received
     */
    public static function isValid(string $url, array $params, string $signature, string $authToken): bool
    {
        if ($signature === '' || $authToken === '') {
            return false;
        }

        ksort($params, SORT_STRING);

        $data = $url;

        foreach ($params as $name => $value) {
            // Nested or repeated parameters are not something Twilio sends; anything that is not
            // a scalar would be our own framework's doing, and is stringified the same way twice.
            $data .= $name.(is_scalar($value) ? (string) $value : '');
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $authToken, true)), $signature);
    }
}
