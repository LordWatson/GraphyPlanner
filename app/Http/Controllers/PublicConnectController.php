<?php

namespace App\Http\Controllers;

use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Models\ConnectInvite;
use App\Models\SocialAccount;
use App\Support\Clients\ConnectSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Step M-2.8 — the public self-connect page a client opens from their link. No login and no
 * password: possession of a live link grants exactly one thing — starting the vendor connect flow
 * for the account slots that link covers, all belonging to its one client.
 */
class PublicConnectController extends Controller
{
    public function show(Request $request, string $token): HttpResponse|Response
    {
        $invite = ConnectInvite::findActive($token);

        // An unknown, expired or revoked link gets a friendly page (410), never a hint of whose it was.
        if ($invite === null) {
            return Inertia::render('connect/show', ['token' => null, 'client' => null, 'agency' => null, 'accounts' => [], 'expires_at' => null])
                ->toResponse($request)
                ->setStatusCode(HttpResponse::HTTP_GONE);
        }

        return Inertia::render('connect/show', [
            'token' => $token,
            'client' => ['name' => $invite->client->name],
            'agency' => $invite->client->organization?->name,
            'accounts' => ConnectSlots::forList($invite->connectableAccounts()),
            'expires_at' => $invite->expires_at->toIso8601String(),
        ]);
    }

    public function connect(string $token, SocialAccount $socialAccount, ConnectSocialAccountAction $action): JsonResponse
    {
        $invite = ConnectInvite::findActive($token);

        if ($invite === null) {
            return response()->json(['message' => __('This link has expired or been switched off. Ask your agency for a new one.')], HttpResponse::HTTP_GONE);
        }

        // Single-client scoping: the link can never start a connection for another client's (or
        // organization's) account, or for a slot it wasn't issued for.
        if (! $invite->allows($socialAccount)) {
            Log::warning('Connect invite used for an account it does not cover', [
                'connect_invite_id' => $invite->id,
                'social_account_id' => $socialAccount->id,
            ]);

            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        try {
            $url = $action($socialAccount, null, $invite);
        } catch (RuntimeException $e) {
            return response()->json(['message' => __('We couldn’t start the connection. Please try again in a moment.')], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['url' => $url]);
    }
}
