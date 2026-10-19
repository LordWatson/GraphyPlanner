<?php

namespace App\Http\Controllers\Portal;

use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step M-2.8 — the portal's "Connect your accounts" panel: a signed-in client contact starts the
 * vendor connect flow for one of their own client's accounts.
 */
class PortalAccountConnectController extends Controller
{
    public function connect(Request $request, SocialAccount $socialAccount, ConnectSocialAccountAction $action): JsonResponse
    {
        $this->authorize('connectAsClient', $socialAccount);

        try {
            $url = $action($socialAccount, $request->user(), null, 'portal');
        } catch (RuntimeException) {
            return response()->json(['message' => __('We couldn’t start the connection. Please try again in a moment.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['url' => $url]);
    }
}
