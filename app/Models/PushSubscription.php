<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Step B-7.4 — one browser push subscription for a user (a user can have several, one per browser).
 * `endpoint_hash` is a sha-256 of the endpoint so it fits a unique index (endpoints can be long),
 * giving a stable key to upsert against when a browser re-subscribes.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $platform null for a browser; `android` / `ios` for a native device token
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string|null $public_key
 * @property string|null $auth_token
 * @property string|null $content_encoding
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** A native-shell device token (delivered over FCM) rather than a browser subscription. */
    public function isNative(): bool
    {
        return $this->platform !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
