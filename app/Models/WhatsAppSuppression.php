<?php

namespace App\Models;

use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Step W-1.3 — a number that must not be messaged.
 *
 * This outranks every preference and every consent flag, and is checked on the way out of
 * `WhatsAppChannel`. It is deliberately not scoped by `BelongsToOrganization`: a `null` org_id is
 * a global suppression, and a lookup has to see those as well as the org's own, so the scoping is
 * explicit in `isSuppressed()` instead of implicit in a global scope.
 *
 * @property int $id
 * @property int|null $org_id
 * @property string $phone
 * @property string $reason
 * @property Carbon|null $created_at
 */
#[Fillable(['org_id', 'phone', 'reason'])]
class WhatsAppSuppression extends Model
{
    // Eloquent would guess `whats_app_suppressions`.
    protected $table = 'whatsapp_suppressions';

    /** They told us to stop. Only they can undo it. */
    public const REASON_STOP = 'stop';

    /** The number is not reachable on WhatsApp, so retrying only annoys whoever does own it. */
    public const REASON_UNDELIVERABLE = 'undeliverable';

    /** Twilio refused the send because the recipient had opted out at its end. */
    public const REASON_OPTED_OUT = 'opted_out';

    /**
     * Whether this number is suppressed for this organization — or globally.
     */
    public static function isSuppressed(string $phone, ?int $orgId): bool
    {
        $e164 = PhoneNumber::e164($phone);

        if ($e164 === null) {
            return false;
        }

        return self::query()
            ->where('phone', $e164)
            ->where(fn ($query) => $query->whereNull('org_id')->orWhere('org_id', $orgId))
            ->exists();
    }

    /**
     * Stop messaging this number. Idempotent: a second STOP is not an error, and a number already
     * suppressed keeps the reason it was first suppressed for.
     */
    public static function suppress(string $phone, string $reason, ?int $orgId = null): void
    {
        $e164 = PhoneNumber::e164($phone);

        if ($e164 === null) {
            return;
        }

        self::query()->firstOrCreate(['phone' => $e164, 'org_id' => $orgId], ['reason' => $reason]);

        Log::info('WhatsApp number suppressed', [
            'org_id' => $orgId,
            'phone' => PhoneNumber::mask($e164),
            'reason' => $reason,
        ]);
    }

    /**
     * Let messages through again — only ever in response to the person themselves (a START).
     */
    public static function release(string $phone, ?int $orgId = null): void
    {
        $e164 = PhoneNumber::e164($phone);

        if ($e164 === null) {
            return;
        }

        // Both the org's own row and any global one: a START is them saying yes, and leaving a
        // global row behind would silently keep them muted.
        self::query()
            ->where('phone', $e164)
            ->where(fn ($query) => $query->whereNull('org_id')->orWhere('org_id', $orgId))
            ->delete();

        Log::info('WhatsApp suppression released', [
            'org_id' => $orgId,
            'phone' => PhoneNumber::mask($e164),
        ]);
    }
}
