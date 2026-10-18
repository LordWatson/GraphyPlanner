<?php

namespace App\Models;

use App\Enums\DeliverableState;
use App\Enums\Platform;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\CampaignDeliverableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-3.2 — a planned slot in a campaign. It becomes a post only when work starts
 * (`StartPostFromDeliverableAction`); its state is derived from that post.
 *
 * `asset_requirements` (Step C-4.1) is a short checklist: `[{label: "1 × 9:16 video", met: bool}]`.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int|null $campaign_phase_id
 * @property string $title
 * @property Platform $platform
 * @property string|null $format
 * @property string|null $pillar
 * @property Carbon|null $planned_for
 * @property string|null $planned_time
 * @property int|null $assignee_id
 * @property string|null $notes
 * @property list<array{label: string, met: bool}>|null $asset_requirements
 * @property int|null $post_id
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'campaign_id', 'campaign_phase_id', 'title', 'platform', 'format', 'pillar', 'planned_for',
    'planned_time', 'assignee_id', 'notes', 'asset_requirements', 'post_id', 'sort',
])]
class CampaignDeliverable extends Model
{
    /** @use HasFactory<CampaignDeliverableFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'planned_for' => 'date',
            'asset_requirements' => 'array',
            'sort' => 'integer',
        ];
    }

    /**
     * Derived from the linked post. A soft-deleted post frees the slot (`post_id` is cleared when
     * the post is deleted), so a missing post always reads "Not started". Expects `post` loaded.
     */
    public function state(): DeliverableState
    {
        return DeliverableState::fromPost($this->post_id === null ? null : $this->post?->status);
    }

    public function unmetRequirementCount(): int
    {
        return count(array_filter($this->asset_requirements ?? [], fn (array $requirement) => $requirement['met'] !== true));
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<CampaignPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(CampaignPhase::class, 'campaign_phase_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
