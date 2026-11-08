<?php

namespace App\Actions\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppSuppression;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Step W-1.1 — send somebody a code to prove the number they typed is theirs.
 *
 * Nobody is messaged on WhatsApp without this, which is both courtesy and Meta policy: an
 * unverified number may be a typo away from a stranger's phone.
 *
 * The code is hashed at rest (like `ReviewToken`) and the number is left **unverified** from the
 * moment it changes, so a member cannot switch the number under an existing opt-in and keep
 * receiving at the old one — or start receiving at a new one they have not proven.
 */
class RequestWhatsAppVerificationAction
{
    private const CODE_TTL_MINUTES = 10;

    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * @throws ValidationException when the number cannot be reached, or one was just sent
     */
    public function __invoke(User $user, string $phone): void
    {
        $e164 = PhoneNumber::e164($phone);

        if ($e164 === null) {
            throw ValidationException::withMessages([
                'phone' => 'Enter the number with its country code, for example +44 7700 900123.',
            ]);
        }

        $organization = $user->organization;

        if (! $organization instanceof Organization) {
            throw ValidationException::withMessages([
                'phone' => 'WhatsApp notifications need an organization.',
            ]);
        }

        if (WhatsAppSuppression::isSuppressed($e164, $organization->id)) {
            // They (or whoever owns the number) told us to stop. Verifying would walk around that.
            throw ValidationException::withMessages([
                'phone' => 'That number has opted out of WhatsApp messages from us. Send "START" to our WhatsApp number from it first.',
            ]);
        }

        $resendAfter = max(0, (int) config('services.whatsapp.verification.resend_seconds', 60));

        if ($user->phone_verification_sent_at?->gt(now()->subSeconds($resendAfter))) {
            throw ValidationException::withMessages([
                'phone' => 'A code was just sent. Wait a minute before asking for another.',
            ]);
        }

        // Six digits, zero-padded: short enough to retype off a phone screen, and the attempt
        // counter below is what makes it safe rather than its length.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $result = $this->client->sendTemplate($organization, $e164, MessageTemplate::VerifyCode->fill(['code' => $code]));

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'phone' => $result->errorReason ?? 'Could not send a code to that number.',
            ]);
        }

        DB::transaction(fn () => $user->forceFill([
            'phone' => $e164,
            // Changing the number un-verifies it, which is the whole point of storing them apart.
            'phone_verified_at' => null,
            'phone_verification_code' => Hash::make($code),
            'phone_verification_sent_at' => now(),
            'phone_verification_attempts' => 0,
        ])->save());
    }

    public static function expiryMinutes(): int
    {
        return self::CODE_TTL_MINUTES;
    }
}
