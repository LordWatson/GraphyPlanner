<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Database\Factories\ReviewTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A token granting scoped, unauthenticated access to the client review portal (Step 0.13).
 * The plain token is never persisted — only its hash — and is only returned once, at creation
 * time, by `App\Actions\ReviewTokens\CreateReviewTokenAction`.
 *
 * @property int $id
 * @property string $token_hash
 * @property int|null $client_id
 * @property int|null $post_id
 * @property int|null $grid_plan_id
 * @property bool $allow_comments
 * @property bool $allow_approval
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token_hash', 'client_id', 'post_id', 'grid_plan_id', 'allow_comments', 'allow_approval', 'expires_at', 'revoked_at'])]
class ReviewToken extends Model
{
    /** @use HasFactory<ReviewTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_comments' => 'boolean',
            'allow_approval' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Step B-3.4 — a token minted by "Share preview" (a partner/sponsor link) rather than the
     * agency client-review flow. Carries at least one widened capability; the review portal reads
     * it to decide whether comments/decisions are offered and to use brand-not-client copy.
     */
    public function isSharePreview(): bool
    {
        return $this->allow_comments || $this->allow_approval;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // Step I-1.5: an archived (soft-deleted) client still owns its records.
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step 18.8: a token issued for a grid plan's client review (never also a post).
     *
     * @return BelongsTo<GridPlan, $this>
     */
    public function gridPlan(): BelongsTo
    {
        return $this->belongsTo(GridPlan::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Whether the token can currently be used to access the review portal.
     */
    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
