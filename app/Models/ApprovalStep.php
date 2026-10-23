<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\ApprovalStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-6.3 — one required approval on a post, built from the client's approver contacts and
 * approval policy when the post enters `waiting_client`. A `null` `client_contact_id` is the
 * generic step for a client with no named approvers (satisfied by the review-link token or any
 * portal reviewer).
 *
 * @property int $id
 * @property int $org_id
 * @property int $post_id
 * @property int|null $client_contact_id
 * @property int $position
 * @property ApprovalDecision|null $decision
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $comment
 * @property int|null $approved_revision_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ClientContact|null $contact
 * @property-read User|null $decidedBy
 * @property-read PostRevision|null $approvedRevision
 */
#[Fillable([
    'org_id', 'post_id', 'client_contact_id', 'position', 'decision',
    'decided_by_user_id', 'decided_at', 'comment', 'approved_revision_id',
])]
class ApprovalStep extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ApprovalStepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->decision === null;
    }

    public function isApproved(): bool
    {
        return $this->decision === ApprovalDecision::Approved;
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<ClientContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id')->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<PostRevision, $this>
     */
    public function approvedRevision(): BelongsTo
    {
        return $this->belongsTo(PostRevision::class, 'approved_revision_id')->withoutGlobalScope(OrganizationScope::class);
    }
}
