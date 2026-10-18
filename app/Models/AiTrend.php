<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A persisted AI-discovered trend (Trends & Ideas panel). Unlike the transient
 * `App\Support\AI\AiTrend` DTO returned straight from `DiscoverTrendsAction`, rows here survive
 * a page refresh — `AiController::trends()` upserts the DTO list into this table (keyed on
 * `client_id`+`title`) every time the panel is refreshed, and the client "show" page lists
 * them from here so the panel isn't empty until someone clicks Refresh again.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $title
 * @property string|null $summary
 * @property array<int, string>|null $platforms
 * @property float $confidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'client_id', 'title', 'summary', 'platforms', 'confidence'])]
class AiTrend extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'confidence' => 'float',
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
        // Step I-1.5: an archived (soft-deleted) client still owns its records.
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }
}
