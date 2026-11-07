<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\McpScope;
use App\Http\Controllers\Controller;
use App\Support\Mcp\McpContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me` — what this token is and what it may do.
 *
 * The first call anyone makes, and the only one that answers "are my credentials right and is this
 * the organization I meant?" without needing to know any other endpoint. Returning the granted
 * scopes (and their labels) is what turns a 403 elsewhere into something a developer can fix
 * themselves rather than a support ticket.
 */
class MeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $context = McpContext::current();

        // Unreachable behind `api.token`, which refuses before this runs; kept so the contract does
        // not depend on middleware order being right forever.
        if ($context === null) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'No token resolved.']], 401);
        }

        return response()->json(['data' => [
            'organization' => [
                'id' => $context->organization->id,
                'name' => $context->organization->name,
                'timezone' => $context->organization->default_timezone,
                'currency' => $context->organization->default_currency,
            ],
            'scopes' => array_map(fn (string $scope): array => [
                'scope' => $scope,
                'label' => McpScope::tryFrom($scope)?->label(),
            ], $context->scopes),
            'attributed_to' => $context->user === null ? null : [
                'id' => $context->user->id,
                'name' => $context->user->name,
            ],
        ]]);
    }
}
