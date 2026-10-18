<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step I-2.6 — one hashtag in a client's vocabulary: how many live posts use it and when it was
 * last used. Maintained by `App\Services\ClientHashtagService`; read for the editor's suggestions.
 * Always reached through its client (no `org_id` of its own), so tenant scoping follows the client.
 *
 * @property int $id
 * @property int $client_id
 * @property string $tag
 * @property string $tag_key
 * @property int $uses
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'tag', 'tag_key', 'uses', 'last_used_at'])]
class ClientHashtag extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uses' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
