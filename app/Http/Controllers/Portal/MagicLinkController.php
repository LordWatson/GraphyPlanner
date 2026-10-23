<?php

namespace App\Http\Controllers\Portal;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PortalLoginToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.2 — the passwordless "magic link" endpoint. A valid, unexpired, unused token signs the
 * client reviewer into their portal (regenerating the session against fixation), consumes the token,
 * and lands them on the emailed post when it's still client-visible — otherwise the portal
 * dashboard. An invalid link falls back to the login page rather than leaking why it failed.
 */
class MagicLinkController extends Controller
{
    public function login(Request $request, string $token): RedirectResponse
    {
        $loginToken = PortalLoginToken::findActive($token);

        if ($loginToken === null) {
            return to_route('login')->with('status', 'That sign-in link has expired. Please log in, or ask the agency for a fresh link.');
        }

        $loginToken->loadMissing(['user', 'post']);
        $user = $loginToken->user;

        // Defensive: the link only ever logs in a client reviewer (its only ever intended holder).
        if ($user === null || $user->role !== Role::ClientReviewer) {
            Log::warning('Portal magic link pointed at a non-reviewer user', [
                'portal_login_token_id' => $loginToken->id,
                'user_id' => $loginToken->user_id,
            ]);

            return to_route('login');
        }

        // Single-use: consume before establishing the session so a replayed link cannot sign in.
        $loginToken->forceFill(['used_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        Log::info('Portal magic link used', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'portal_login_token_id' => $loginToken->id,
            'post_id' => $loginToken->post_id,
        ]);

        $post = $loginToken->post;

        if ($post !== null && $post->client_id === $user->client_id && $post->status->isClientVisible()) {
            return to_route('portal.posts.show', $post);
        }

        return to_route('portal.dashboard');
    }
}
