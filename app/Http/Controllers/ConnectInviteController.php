<?php

namespace App\Http\Controllers;

use App\Actions\SocialAccounts\CreateConnectInviteAction;
use App\Actions\SocialAccounts\RevokeConnectInviteAction;
use App\Http\Requests\StoreConnectInviteRequest;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Step M-2.8 — staff create and revoke client self-connect links (Accounts tab).
 */
class ConnectInviteController extends Controller
{
    /**
     * JSON, so the dialog can show the link once without a page visit (it's never retrievable
     * again).
     */
    public function store(StoreConnectInviteRequest $request, Client $client, CreateConnectInviteAction $action): JsonResponse
    {
        ['invite' => $invite, 'url' => $url] = $action($client, $request->user(), $request->validated());

        return response()->json([
            'url' => $url,
            'invite' => self::present($invite),
        ], 201);
    }

    public function destroy(Request $request, ConnectInvite $connectInvite, RevokeConnectInviteAction $action): RedirectResponse
    {
        $this->authorize('createConnectInvite', [SocialAccount::class, $connectInvite->client]);

        $action($connectInvite, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Link revoked. It no longer works.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ConnectInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'scope' => $invite->scopeLabel(),
            'expires_at' => $invite->expires_at->toIso8601String(),
            'used_at' => $invite->used_at?->toIso8601String(),
            'connected_count' => count($invite->connected_account_ids ?? []),
            'created_by' => $invite->creator?->name,
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }
}
