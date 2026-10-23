<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PostRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-5.3 — one saved version of a post's content. The snapshot is content only (caption,
 * hashtags, music, location, link, first comment, media format, per-channel overrides and media) —
 * never the schedule or publish state, which aren't "what the client reviews".
 *
 * @property int $id
 * @property int $org_id
 * @property int $post_id
 * @property int|null $created_by
 * @property array<string, mixed> $snapshot
 * @property Carbon|null $shared_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'post_id', 'created_by', 'snapshot', 'shared_at'])]
class PostRevision extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PostRevisionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'shared_at' => 'datetime',
        ];
    }

    /**
     * Step M-6.3 — a stable content hash of the exact version the client reviewed, printed on the
     * approval certificate so the approved content is verifiable and tamper-evident.
     */
    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode($this->snapshot));
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
