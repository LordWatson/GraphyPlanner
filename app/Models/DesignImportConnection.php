<?php

namespace App\Models;

use App\Enums\DesignImportProvider;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step M-5.9 — an org's OAuth connection to a design/cloud import source. Tokens are encrypted at
 * rest and must never be logged.
 *
 * @property int $id
 * @property int $org_id
 * @property DesignImportProvider $provider
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $account_label
 * @property int|null $created_by
 * @property Carbon|null $revoked_at
 */
#[Fillable([
    'org_id', 'provider', 'access_token', 'refresh_token', 'expires_at', 'account_label', 'created_by', 'revoked_at',
])]
class DesignImportConnection extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => DesignImportProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Live connections only — not revoked.
     *
     * @param  Builder<DesignImportConnection>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
