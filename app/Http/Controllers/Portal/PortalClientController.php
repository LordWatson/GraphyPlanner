<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.7 — the client portal's read-only "company" page: the logged-in contact's own `Client`
 * record (identity/profile fields only), scoped by `EnsureClientPortalAccess` to the user's own
 * `client_id`. No billing-sensitive fields (retainer, billing cycle) are exposed here, mirroring
 * `ClientPolicy::viewBilling`'s "no billing visibility" boundary for `Role::ClientReviewer`.
 */
class PortalClientController extends Controller
{
    public function show(Request $request): Response
    {
        $client = $request->user()->client;

        return Inertia::render('portal/company', [
            'client' => $this->transform($client),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'website' => $client->website,
            'industry' => $client->industry,
            'countries' => $client->countries ?? [],
            'status' => $client->status->value,
            'status_label' => $client->status->label(),
            'default_language' => $client->default_language,
            'start_date' => $client->start_date?->toDateString(),
        ];
    }
}
