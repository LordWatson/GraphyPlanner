<?php

namespace App\Listeners;

use App\Http\Controllers\PushDeviceController;
use App\Models\PushSubscription;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;

/**
 * Native shell — signing out on a phone stops that phone's pushes. The device row registered in
 * this session is remembered in the session ({@see PushDeviceController}),
 * so only this phone's token goes: the user's other devices and browsers keep their opt-in.
 */
class ForgetPushDeviceOnLogout
{
    public const SESSION_KEY = 'push_device_id';

    public function handle(Logout $event): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $id = request()->session()->pull(self::SESSION_KEY);

        if ($id === null || $event->user === null) {
            return;
        }

        $deleted = PushSubscription::query()
            ->whereKey($id)
            ->where('user_id', $event->user->getAuthIdentifier())
            ->whereNotNull('platform')
            ->delete();

        if ($deleted > 0) {
            Log::info('Push device removed on logout', ['user_id' => $event->user->getAuthIdentifier()]);
        }
    }
}
