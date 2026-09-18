<?php

namespace App\Http\Controllers;

use App\Actions\ClientInvitations\CreateClientInvitationAction;
use App\Http\Requests\StoreClientInvitationRequest;
use App\Models\Client;
use App\Models\ClientInvitation;
use Illuminate\Http\RedirectResponse;

class ClientInvitationController extends Controller
{
    /**
     * Invite a client contact to the portal. Pending/accepted invitations themselves are listed
     * inline on the client show page (via `ClientController::show`), the same way invoices and
     * social accounts are, rather than through a dedicated list endpoint.
     */
    public function store(StoreClientInvitationRequest $request, Client $client, CreateClientInvitationAction $action): RedirectResponse
    {
        $action($client, $request->validated('email'), $request->user());

        return to_route('clients.show', $client);
    }

    /**
     * Revoke a pending invitation.
     */
    public function destroy(ClientInvitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->update(['revoked_at' => now()]);

        return to_route('clients.show', $invitation->client_id);
    }
}
