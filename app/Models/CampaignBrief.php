<?php

namespace App\Models;

use App\Enums\BriefStatus;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Step C-2.1 — the structured campaign brief, one per campaign.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string|null $background
 * @property string|null $objective_detail
 * @property string|null $audience
 * @property list<string>|null $key_messages
 * @property string|null $offer
 * @property string|null $call_to_action
 * @property list<string>|null $mandatories
 * @property list<string>|null $avoid
 * @property string|null $deliverables_summary
 * @property string|null $success_measures
 * @property BriefStatus $status
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'campaign_id', 'background', 'objective_detail', 'audience', 'key_messages', 'offer',
    'call_to_action', 'mandatories', 'avoid', 'deliverables_summary', 'success_measures', 'status',
    'approved_at', 'approved_by', 'updated_by',
])]
class CampaignBrief extends Model
{
    /**
     * The content fields, in the order the brief reads (A — Background, B — Objective…). Shared
     * by the editor, revisions, the content hash, the PDF and the AI draft.
     */
    public const CONTENT_FIELDS = [
        'background', 'objective_detail', 'audience', 'key_messages', 'offer', 'call_to_action',
        'mandatories', 'avoid', 'deliverables_summary', 'success_measures',
    ];

    /**
     * Content fields that hold a list of short lines rather than prose.
     */
    public const LIST_FIELDS = ['key_messages', 'mandatories', 'avoid'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BriefStatus::class,
            'key_messages' => 'array',
            'mandatories' => 'array',
            'avoid' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The brief's content as a plain array (every content field, in order).
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        $content = [];

        foreach (self::CONTENT_FIELDS as $field) {
            $content[$field] = in_array($field, self::LIST_FIELDS, true)
                ? ($this->{$field} ?? [])
                : $this->{$field};
        }

        return $content;
    }

    /**
     * A stable SHA-256 of the content, recorded on each revision so a decision provably refers to
     * the exact words the client saw.
     *
     * @param  array<string, mixed>  $content
     */
    public static function hashContent(array $content): string
    {
        return hash('sha256', (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function isEmpty(): bool
    {
        foreach ($this->content() as $value) {
            if (is_array($value) ? $value !== [] : filled($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<CampaignBriefRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CampaignBriefRevision::class);
    }

    /**
     * @return HasOne<CampaignBriefRevision, $this>
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(CampaignBriefRevision::class)->latestOfMany('number');
    }
}
