<?php

namespace App\Http\Controllers;

use App\Actions\Push\DeletePushSubscriptionAction;
use App\Actions\Push\RegisterPushDeviceAction;
use App\Http\Requests\StorePushDeviceRequest;
use App\Listeners\ForgetPushDeviceOnLogout;
use App\Services\Push\FcmPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native shell — a user turns push on or off for the phone they are holding. The same opt-in as
 * {@see PushSubscriptionController}, for an FCM device token instead of a browser subscription.
 */
class PushDeviceController extends Controller
{
    public function store(StorePushDeviceRequest $request, RegisterPushDeviceAction $action): JsonResponse
    {
        abort_unless(FcmPushNotifier::nativeConfigured(), 404);

        $device = $action(
            $request->user(),
            $request->string('token')->toString(),
            $request->string('platform')->toString(),
        );

        // So signing out on this phone can drop this phone's token (and only this one).
        $request->session()->put(ForgetPushDeviceOnLogout::SESSION_KEY, $device->id);

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, DeletePushSubscriptionAction $action): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $deleted = $action($request->user(), $validated['token']);
        $request->session()->forget(ForgetPushDeviceOnLogout::SESSION_KEY);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
