<?php

namespace App\Http\Controllers;

use App\Actions\StaffInvitations\AcceptStaffInvitationAction;
use App\Http\Requests\AcceptStaffInvitationRequest;
use App\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The staff accept-invitation flow (`/staff-invite/:token`): a light, unauthenticated view —
 * mirrors the `/client-invite/:token` portal — that lets an invited staff member set a password
 * and create their login. Access is granted purely by possession of a valid, unexpired,
 * unrevoked, unaccepted `StaffInvitation` token — never a logged-in session.
 */
class StaffInvitationAcceptController extends Controller
{
    /**
     * Show the accept-invitation form for this token's organization.
     */
    public function show(string $token): Response
    {
        $invitation = $this->resolveInvitation($token);

        return Inertia::render('staff-invite/show', [
            'token' => $token,
            'organization' => ['name' => $invitation->organization->name],
            'email' => $invitation->email,
            'role_label' => $invitation->role->label(),
        ]);
    }

    /**
     * Create (or reuse) the staff member's login and mark the invitation accepted.
     */
    public function store(
        AcceptStaffInvitationRequest $request,
        string $token,
        AcceptStaffInvitationAction $action,
    ): RedirectResponse {
        $invitation = $this->resolveInvitation($token);

        $action($invitation, $request->validated('name'), $request->validated('password'));

        return to_route('login')->with('status', 'Your account has been created — you can now log in.');
    }

    /**
     * Resolve the invitation scoped strictly to a valid, still-pending token.
     */
    private function resolveInvitation(string $token): StaffInvitation
    {
        $invitation = StaffInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation || ! $invitation->isPending()) {
            throw new NotFoundHttpException;
        }

        return $invitation;
    }
}
