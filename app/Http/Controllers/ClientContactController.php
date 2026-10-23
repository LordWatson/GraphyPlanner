<?php

namespace App\Http\Controllers;

use App\Actions\Clients\CreateClientContactAction;
use App\Actions\Clients\DeleteClientContactAction;
use App\Actions\Clients\UpdateClientContactAction;
use App\Http\Requests\StoreClientContactRequest;
use App\Http\Requests\UpdateClientContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;

/**
 * Step M-6.1 — client contacts. Thin: the Form Request authorizes and validates, an Action does the
 * work, and the response lands back on the Portal access tab where contacts are managed inline.
 */
class ClientContactController extends Controller
{
    public function store(StoreClientContactRequest $request, Client $client, CreateClientContactAction $action): RedirectResponse
    {
        $action($client, $request->payload());

        return to_route('clients.portal-access', $client);
    }

    public function update(UpdateClientContactRequest $request, ClientContact $contact, UpdateClientContactAction $action): RedirectResponse
    {
        $action($contact, $request->payload());

        return to_route('clients.portal-access', $contact->client_id);
    }

    public function destroy(ClientContact $contact, DeleteClientContactAction $action): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $clientId = $contact->client_id;
        $action($contact);

        return to_route('clients.portal-access', $clientId);
    }
}
