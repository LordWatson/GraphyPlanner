<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-2.3 — a frozen copy of a brief as sent to the client, numbered per brief, with the
 * content hash and the client's decision recorded against it.
 *
 * @property int $id
 * @property int $campaign_brief_id
 * @property int $number
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property int|null $sent_by
 * @property Carbon $sent_at
 * @property ApprovalDecision|null $decision
 * @property string|null $decision_comment
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'campaign_brief_id', 'number', 'content', 'content_hash', 'sent_by', 'sent_at', 'decision',
    'decision_comment', 'decided_by', 'decided_at',
])]
class CampaignBriefRevision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'sent_at' => 'datetime',
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CampaignBrief, $this>
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(CampaignBrief::class, 'campaign_brief_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
