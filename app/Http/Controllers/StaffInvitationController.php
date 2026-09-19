<?php

namespace App\Http\Controllers;

use App\Actions\StaffInvitations\CreateStaffInvitationAction;
use App\Enums\Role;
use App\Http\Requests\StoreStaffInvitationRequest;
use App\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;

class StaffInvitationController extends Controller
{
    /**
     * Invite a staff member to the organization. Pending/accepted invitations themselves are
     * listed inline on the staff index page, the same way client invitations are listed on
     * the client show page.
     */
    public function store(StoreStaffInvitationRequest $request, CreateStaffInvitationAction $action): RedirectResponse
    {
        $action(
            $request->user()->organization,
            $request->validated('email'),
            $request->enum('role', Role::class),
            $request->user(),
        );

        return to_route('staff.index');
    }

    /**
     * Revoke a pending invitation.
     */
    public function destroy(StaffInvitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->update(['revoked_at' => now()]);

        return to_route('staff.index');
    }
}
