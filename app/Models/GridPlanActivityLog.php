<?php

namespace App\Models;

use App\Enums\GridPlanStatus;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step 18.1 — one move of a grid plan through its workflow (same shape as `PostActivityLog`).
 * `review_token_id` records that a review link was emailed; the link itself is never stored.
 *
 * @property int $id
 * @property int $grid_plan_id
 * @property int|null $user_id
 * @property GridPlanStatus|null $from_status
 * @property GridPlanStatus $to_status
 * @property string|null $note
 * @property int|null $review_token_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['grid_plan_id', 'user_id', 'from_status', 'to_status', 'note', 'review_token_id'])]
class GridPlanActivityLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => GridPlanStatus::class,
            'to_status' => GridPlanStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ReviewToken, $this>
     */
    public function reviewToken(): BelongsTo
    {
        return $this->belongsTo(ReviewToken::class);
    }
}
