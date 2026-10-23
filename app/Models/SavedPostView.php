<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SavedPostViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-5.1 — a named, saved filter query for the content hub (`/content`). Per-user and never
 * shared, so it carries no cross-member visibility concerns; `SavedPostViewPolicy` still pins every
 * action to the owning user and organization.
 *
 * @property int $id
 * @property int $org_id
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'user_id', 'name', 'filters'])]
class SavedPostView extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SavedPostViewFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
