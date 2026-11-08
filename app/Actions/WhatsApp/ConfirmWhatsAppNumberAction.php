<?php

namespace App\Actions\WhatsApp;

use App\Models\User;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Step W-1.1 — check the code and mark the number verified.
 *
 * Three ways to fail, all of them the same message to the person typing: no code outstanding, the
 * code has expired, or it is wrong. The attempt counter is what makes six digits safe — five tries
 * and the code is discarded, so guessing costs a new code (and another WhatsApp message) each
 * time.
 */
class ConfirmWhatsAppNumberAction
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, string $code): void
    {
        $expiresAt = $user->phone_verification_sent_at
            ?->addMinutes(RequestWhatsAppVerificationAction::expiryMinutes());

        if ($user->phone_verification_code === null || $expiresAt === null || $expiresAt->isPast()) {
            $this->fail('That code has expired. Ask for a new one.');
        }

        if ($user->phone_verification_attempts >= self::MAX_ATTEMPTS) {
            // Discarded rather than locked: a new code is the way forward, and it costs them a
            // message rather than costing us a permanently unusable row.
            $this->clear($user);

            $this->fail('Too many attempts. Ask for a new code.');
        }

        if (! Hash::check($code, (string) $user->phone_verification_code)) {
            $user->forceFill(['phone_verification_attempts' => $user->phone_verification_attempts + 1])->save();

            $this->fail('That code is not right.');
        }

        DB::transaction(fn () => $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_code' => null,
            'phone_verification_attempts' => 0,
        ])->save());
    }

    /**
     * Step W-1.1 — take the number off a member entirely: no number, no verification, and every
     * WhatsApp preference back off. Leaving the preferences on would quietly start messaging them
     * again the day they verified a new number.
     */
    public function forget(User $user): void
    {
        DB::transaction(function () use ($user) {
            $preferences = $user->notification_preferences ?? [];

            foreach (array_keys($preferences) as $category) {
                $preferences[$category]['whatsapp'] = false;
            }

            $user->forceFill([
                'phone' => null,
                'phone_verified_at' => null,
                'phone_verification_code' => null,
                'phone_verification_sent_at' => null,
                'phone_verification_attempts' => 0,
                'notification_preferences' => NotificationPreferences::sanitize([], $preferences),
            ])->save();
        });
    }

    private function clear(User $user): void
    {
        $user->forceFill([
            'phone_verification_code' => null,
            'phone_verification_attempts' => 0,
        ])->save();
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
