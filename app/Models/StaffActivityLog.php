<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\StaffActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single event in a staff member's history (invited, joined, role changed, removed) — shown
 * on `staff/show` so Owners have an audit trail for who did what to whom.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $user_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property Role|null $from_role
 * @property Role|null $to_role
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'user_id', 'actor_user_id', 'action', 'from_role', 'to_role', 'note'])]
class StaffActivityLog extends Model
{
    /** @use HasFactory<StaffActivityLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_role' => Role::class,
            'to_role' => Role::class,
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
     * The staff member the log entry is about.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who performed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
