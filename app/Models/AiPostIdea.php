<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A persisted AI-recommended post idea (Trends & Ideas panel). Unlike the transient
 * `App\Support\AI\AiPostIdea` DTO returned straight from `RecommendPostsAction`, rows here
 * survive a page refresh — `AiController::ideas()` upserts the DTO list into this table (keyed
 * on `client_id`+`title`) every time the panel is refreshed. `post_id` is filled in once the
 * idea has been turned into a real draft post via `RecommendPostsAction::persist()`.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $title
 * @property string|null $description
 * @property array<int, string>|null $platforms
 * @property string|null $suggested_caption
 * @property string|null $suggested_post_at
 * @property int|null $post_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'client_id', 'title', 'description', 'platforms', 'suggested_caption', 'suggested_post_at', 'post_id'])]
class AiPostIdea extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platforms' => 'array',
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

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
