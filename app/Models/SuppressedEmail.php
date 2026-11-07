<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * An address we have stopped sending to, because it hard-bounced or reported us as spam.
 *
 * Not org-scoped: the reputation being protected belongs to the sending domain, which every
 * organization shares, and an address that complained about one agency's mail has not consented to
 * another's.
 *
 * @property int $id
 * @property string $email
 * @property string $reason
 * @property string|null $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'reason', 'source'])]
class SuppressedEmail extends Model
{
    public const REASON_BOUNCED = 'bounced';

    public const REASON_COMPLAINED = 'complained';

    /**
     * Whether we must not send to this address.
     */
    public static function isSuppressed(?string $email): bool
    {
        $email = self::normalise($email);

        return $email !== '' && self::query()->where('email', $email)->exists();
    }

    /**
     * Record a suppression, idempotently — a provider will report the same bounce more than once,
     * and the first reason is the one worth keeping.
     */
    public static function record(string $email, string $reason, ?string $source = null): void
    {
        $email = self::normalise($email);

        if ($email === '') {
            return;
        }

        $created = self::query()->insertOrIgnore([
            'email' => $email,
            'reason' => $reason,
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($created > 0) {
            // Never the address itself at info level: a log line is a wider audience than the
            // mailbox. The domain is enough to spot a pattern.
            Log::info('Email address suppressed', [
                'reason' => $reason,
                'source' => $source,
                'domain' => substr($email, strpos($email, '@') ?: 0),
            ]);
        }
    }

    private static function normalise(?string $email): string
    {
        return strtolower(trim((string) $email));
    }
}
