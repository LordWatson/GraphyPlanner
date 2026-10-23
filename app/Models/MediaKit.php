<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step B-8.4 — a creator/business media kit: one per primary brand. The public share link is
 * opt-in (no `share_token` until the owner turns it on) and revocable (clearing the token kills
 * every link). The rendered page carries only already-public data, so the token is a random public
 * slug rather than a secret. Not org-scoped by a global scope (like {@see CampaignShareLink}): the
 * owner only ever reaches its own kit through the workspace brand, and the public route looks it up
 * by token.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string|null $bio
 * @property string|null $share_token
 * @property Carbon|null $shared_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'client_id', 'bio', 'share_token', 'shared_at'])]
class MediaKit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isPublic(): bool
    {
        return $this->share_token !== null;
    }
}
