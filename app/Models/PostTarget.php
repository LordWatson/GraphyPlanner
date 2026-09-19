<?php

namespace App\Models;

use App\Enums\PostTargetStatus;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $social_account_id
 * @property Carbon|null $scheduled_local_date
 * @property string|null $scheduled_local_time
 * @property Carbon|null $scheduled_at_utc
 * @property Carbon|null $reminder_sent_at
 * @property PostTargetStatus $status
 * @property string|null $external_post_id
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id', 'social_account_id', 'scheduled_local_date', 'scheduled_local_time', 'scheduled_at_utc',
    'reminder_sent_at', 'status', 'external_post_id', 'error',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_local_date' => 'date',
            'scheduled_at_utc' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'status' => PostTargetStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
