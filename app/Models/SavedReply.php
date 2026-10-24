<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\SavedReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Step M-9.2 — a canned inbox reply. Org-wide when `client_id` is null, or scoped to one client so a
 * brand's own phrasings only show for that brand. The composer inserts the `body`; nothing sends
 * until a human presses Send.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $client_id
 * @property int|null $created_by
 * @property string $title
 * @property string $body
 * @property string|null $shortcut
 * @property int $usage_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'created_by', 'title', 'body', 'shortcut', 'usage_count',
])]
class SavedReply extends Model
{
    /** @use HasFactory<SavedReplyFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Replies available to a given client: the org-wide ones plus that client's own.
     *
     * @param  Builder<SavedReply>  $query
     */
    public function scopeAvailableForClient(Builder $query, int $clientId): void
    {
        $query->where(fn (Builder $inner) => $inner->whereNull('client_id')->orWhere('client_id', $clientId));
    }
}
