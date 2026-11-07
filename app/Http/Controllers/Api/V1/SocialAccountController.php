<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/social-accounts` — the connected channels, and whether they are actually working.
 *
 * `connection_status` is the field integrations care about: "why did nothing publish yesterday" is
 * almost always an expired token, and this is how a monitoring dashboard can see that without a
 * person opening the app.
 *
 * No credentials of any kind are exposed — not the vendor key, not the profile, not the token
 * state beyond its status. An API response is a wider audience than a settings page.
 */
class SocialAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SocialAccount::query()
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->string('platform')))
            ->orderBy('id');

        return ApiResponse::paginate($request, $query, fn (SocialAccount $account) => [
            'id' => $account->id,
            'client_id' => $account->client_id,
            'platform' => $account->platform->value,
            'handle' => $account->handle,
            'timezone' => $account->timezone,
            'connection_status' => $account->connection_status->value,
            'created_at' => $account->created_at?->toIso8601String(),
        ]);
    }
}
