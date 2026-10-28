<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PlanAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase CG-2 (Step CG-2.6 / B-9.4) — a single super-admin billing action on an org: a plan override,
 * a temporary AI credit grant, or a per-org billing-switch override. Shown on the super-admin org
 * detail page as an audit trail.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $from_plan
 * @property string|null $to_plan
 * @property string|null $note
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'actor_user_id', 'action', 'from_plan', 'to_plan', 'note', 'meta'])]
class PlanAuditLog extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PlanAuditLogFactory> */
    use HasFactory;

    public const ACTION_PLAN_CHANGED = 'plan_changed';

    public const ACTION_CREDITS_GRANTED = 'credits_granted';

    public const ACTION_BILLING_OVERRIDE = 'billing_override';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
