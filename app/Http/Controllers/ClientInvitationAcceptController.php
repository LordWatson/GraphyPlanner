<?php

namespace App\Http\Controllers;

use App\Actions\ClientInvitations\AcceptClientInvitationAction;
use App\Http\Requests\AcceptClientInvitationRequest;
use App\Models\ClientInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Step 6.2 accept-invitation flow (`/client-invite/:token`): a light, unauthenticated view —
 * mirrors the `/review/:token` portal (Step 0.13) — that lets a named client contact set a
 * password and create their `Role::ClientReviewer` login. Access is granted purely by possession
 * of a valid, unexpired, unrevoked, unaccepted `ClientInvitation` token — never a logged-in
 * session.
 */
class ClientInvitationAcceptController extends Controller
{
    /**
     * Show the accept-invitation form for this token's client.
     */
    public function show(string $token): Response
    {
        $invitation = $this->resolveInvitation($token);

        return Inertia::render('client-invite/show', [
            'token' => $token,
            'client' => ['name' => $invitation->client->name],
            'email' => $invitation->email,
        ]);
    }

    /**
     * Create (or reuse) the client contact's login and mark the invitation accepted.
     */
    public function store(
        AcceptClientInvitationRequest $request,
        string $token,
        AcceptClientInvitationAction $action,
    ): RedirectResponse {
        $invitation = $this->resolveInvitation($token);

        $user = $action($invitation, $request->validated('name'), $request->validated('password'));

        // Step M-6.2 (I-8.1): one client experience — accepting the invite signs the contact straight
        // into their portal rather than bouncing them to a second login screen. The session is
        // regenerated against fixation, as the login flow does.
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('portal.dashboard');
    }

    /**
     * Resolve the invitation scoped strictly to a valid, still-pending token (spec §10/§12).
     */
    private function resolveInvitation(string $token): ClientInvitation
    {
        $invitation = ClientInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // Step I-1.5: an archived client's invitations can't be accepted (`client` is null then).
        if (! $invitation || ! $invitation->isPending() || $invitation->client === null) {
            throw new NotFoundHttpException;
        }

        return $invitation;
    }
}
