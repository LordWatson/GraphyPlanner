<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Step 18.1 — a comment on a grid plan (same shape as `PostComment`), optionally pinned to one
 * tile (`grid_tile_id`). `internal_only` comments never reach the client.
 *
 * @property int $id
 * @property int $grid_plan_id
 * @property int|null $grid_tile_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $internal_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['grid_plan_id', 'grid_tile_id', 'user_id', 'body', 'internal_only'])]
class GridComment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GridPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(GridPlan::class, 'grid_plan_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<GridTile, $this>
     */
    public function tile(): BelongsTo
    {
        return $this->belongsTo(GridTile::class, 'grid_tile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who wrote it: a team member or portal contact by name, or "The client" for a comment left
     * through a review link (no user).
     */
    public function authorName(): string
    {
        /** @var User|null $user */
        $user = $this->user_id === null ? null : $this->getRelationValue('user') ?? $this->user()->first();

        return $user === null ? 'The client' : $user->name;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'grid_comment_mentions')->withTimestamps();
    }
}
