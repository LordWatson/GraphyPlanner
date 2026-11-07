<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A word the studio wants to hear about the moment it appears in a comment — a brand name, a
 * product, a complaint that keeps coming back.
 *
 * Org-wide on purpose: an agency watching "refund" wants to know wherever it is said, and the alert
 * names the client it came from. Per-client lists would double the surface for no question anyone
 * asked. This is **not** social listening: it only reads the comments the app already ingests for
 * connected accounts (see `MentionAlerts`).
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $created_by
 * @property string $term
 * @property int $match_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'created_by', 'term'])]
class MentionKeyword extends Model
{
    use BelongsToOrganization;

    /**
     * Watched words per organisation. A ceiling, because every term is a regex run against every
     * new comment: 50 is far more than an agency will type, and nothing against the sweep's cost.
     */
    public const MAX_PER_ORG = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
