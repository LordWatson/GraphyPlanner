<?php

namespace App\Models;

use App\Enums\AiRating;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Step M-7.1 (dev plan Step 2.9) — one user's rating of one AI suggestion (a persisted
 * {@see AiTrend}, {@see AiPostIdea}, or a {@see Post} whose caption came from AI). Polymorphic so
 * every AI surface shares the table; unique per user/subject so a rating is idempotent. Never
 * stores the suggestion text — only who rated what, how, and an optional short reason.
 *
 * @property int $id
 * @property int $org_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $user_id
 * @property AiRating $rating
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'subject_type', 'subject_id', 'user_id', 'rating', 'reason'])]
class AiFeedback extends Model
{
    use BelongsToOrganization;

    protected $table = 'ai_feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => AiRating::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
