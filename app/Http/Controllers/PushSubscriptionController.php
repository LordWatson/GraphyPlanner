<?php

namespace App\Http\Controllers;

use App\Actions\Push\DeletePushSubscriptionAction;
use App\Actions\Push\SavePushSubscriptionAction;
use App\Contracts\PushNotifier;
use App\Http\Requests\StorePushSubscriptionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step B-7.4 — a user subscribes or unsubscribes the current browser to push reminders. Per-user
 * and per-browser: the opt-in is reachable by every business role, and unsubscribing deletes only
 * the caller's own row (the opt-out).
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, SavePushSubscriptionAction $action, PushNotifier $notifier): JsonResponse
    {
        abort_unless($notifier->isConfigured(), 404);

        $action(
            $request->user(),
            $request->string('endpoint')->toString(),
            $request->string('keys.p256dh')->toString(),
            $request->string('keys.auth')->toString(),
            $request->filled('contentEncoding') ? $request->string('contentEncoding')->toString() : null,
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, DeletePushSubscriptionAction $action): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $deleted = $action($request->user(), $validated['endpoint']);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
