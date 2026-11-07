<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/clients` — the roster this token can see.
 *
 * Tenancy is not checked here and must not be: `OrganizationScope` pins the organization from the
 * token, and `ClientAccessScope` narrows it further if the organization restricts staff to
 * assigned clients. Adding an explicit `where('org_id', …)` would duplicate that rule in a second
 * place, where it could later disagree.
 *
 * The response shape is deliberately *not* the UI's client payload: that one carries health
 * signals, retainer figures and permission flags shaped for a React page, and changing it for the
 * UI's benefit would be a breaking API change. This is a small, stable contract instead.
 */
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Archived clients are soft-deleted; a reporting client asks for them explicitly.
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->orderBy('id');

        return ApiResponse::paginate($request, $query, fn (Client $client) => self::present($client));
    }

    public function show(Client $client): JsonResponse
    {
        // Route-model binding resolves without the access scopes so a policy can answer — the
        // `Gate::before` in `AppServiceProvider` is what refuses another client's record.
        $this->authorize('view', $client);

        return ApiResponse::item(self::present($client));
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'status' => $client->status->value,
            'health' => $client->health_status?->value,
            'archived' => $client->trashed(),
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }
}
