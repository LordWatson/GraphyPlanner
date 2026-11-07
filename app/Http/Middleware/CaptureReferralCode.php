<?php

namespace App\Http\Middleware;

use App\Models\ReferralPartner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step GTM-7.3 — remember `?ref=CODE` so a signup days later still credits the partner who sent
 * them. On the `web` group, so it works from any marketing page rather than only the signup form.
 *
 * Three deliberate choices:
 *
 *  - **The code is validated before it is stored.** An unknown or ended code is dropped, so the
 *    cookie can only ever hold a real partner's code and the signup path has nothing to re-check.
 *  - **First touch wins.** An existing cookie is not overwritten, because the partner who
 *    introduced somebody is the one who earned it, not whichever link they happened to click last.
 *  - **Nothing is stored about the visitor.** One cookie holding one code, no identifier, no
 *    fingerprint, and no row in the database until they actually sign up.
 */
class CaptureReferralCode
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('ref');

        if (! is_string($code) || $request->hasCookie(ReferralPartner::COOKIE)) {
            return $next($request);
        }

        $partner = ReferralPartner::findByCode($code);

        if ($partner === null) {
            return $next($request);
        }

        Log::info('Referral code captured', ['referral_partner_id' => $partner->id]);

        return $next($request)->withCookie(Cookie::make(
            ReferralPartner::COOKIE,
            $partner->code,
            ReferralPartner::COOKIE_DAYS * 24 * 60,
        ));
    }
}
