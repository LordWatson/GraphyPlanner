<?php

namespace App\Models;

use App\Enums\CampaignObjective;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-3.4 — a reusable campaign shape, org-scoped. Dates are stored as day offsets from the
 * campaign start, so a template can be applied to any client on any date.
 *
 * - `brief`: content fields of a brief, with placeholders such as `{client_name}`.
 * - `phases`: `[{name, start_offset, length, colour}]`.
 * - `deliverables`: `[{title, platform, format, pillar, phase_index, day_offset, time}]`.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $created_by
 * @property string $name
 * @property string|null $description
 * @property CampaignObjective|null $objective
 * @property int $duration_days
 * @property array<string, mixed>|null $brief
 * @property list<array{name: string, start_offset: int, length: int, colour: string|null}>|null $phases
 * @property list<array{title: string, platform: string, format: string|null, pillar: string|null, phase_index: int|null, day_offset: int, time: string|null}>|null $deliverables
 * @property bool $is_starter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'created_by', 'name', 'description', 'objective', 'duration_days', 'brief', 'phases',
    'deliverables', 'is_starter',
])]
class CampaignTemplate extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objective' => CampaignObjective::class,
            'duration_days' => 'integer',
            'brief' => 'array',
            'phases' => 'array',
            'deliverables' => 'array',
            'is_starter' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
