<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $display_name
 * @property string $timezone
 * @property string|null $language
 * @property string|null $country
 * @property string|null $default_location
 * @property array<int, array{day: string, start: string, end: string}>|null $posting_windows
 * @property string|null $persona_override
 * @property ConnectionStatus $connection_status
 * @property string|null $provider
 * @property string|null $external_profile_id
 * @property string|null $external_account_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'platform', 'handle', 'display_name', 'timezone',
    'language', 'country', 'default_location', 'posting_windows', 'persona_override',
    'connection_status', 'provider', 'external_profile_id', 'external_account_id', 'connected_at',
])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'connection_status' => ConnectionStatus::class,
            'posting_windows' => 'array',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<PostTarget, $this>
     */
    public function postTargets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }
}
