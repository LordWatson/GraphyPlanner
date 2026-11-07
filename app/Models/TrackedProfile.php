<?php

namespace App\Models;

use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Step GTM-4.1 — a public profile a brand is measured against. Not ours: there is no token, no
 * content and no relationship to a `SocialAccount`, only a platform and a handle.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $label
 * @property int|null $added_by
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TrackedProfileMetric> $metrics
 */
#[Fillable(['org_id', 'client_id', 'platform', 'handle', 'label', 'added_by', 'last_synced_at', 'last_error'])]
class TrackedProfile extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['platform' => Platform::class, 'last_synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return HasMany<TrackedProfileMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(TrackedProfileMetric::class);
    }

    /**
     * What to call this profile on a chart: the label the owner gave it, else the handle.
     */
    public function displayName(): string
    {
        return $this->label ?: '@'.ltrim($this->handle, '@');
    }

    /**
     * Stored without the leading `@` and lowercased, because a handle is case-insensitive and the
     * unique index would otherwise let `@Rival` and `@rival` both in.
     */
    public static function normaliseHandle(string $handle): string
    {
        return Str::lower(ltrim(trim($handle), '@'));
    }
}
