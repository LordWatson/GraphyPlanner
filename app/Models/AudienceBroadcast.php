<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step GTM-2.3 — one email the owner wrote and sent to a segment. `sent_at` is the record of the
 * send *and* the lock on it: a broadcast is sent once, by a person, and never by a job.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int|null $created_by
 * @property string $subject
 * @property string $body
 * @property string $segment
 * @property int|null $product_id
 * @property Carbon|null $sent_at
 * @property int $recipients
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'created_by', 'subject', 'body', 'segment', 'product_id',
    'sent_at', 'recipients', 'sent_count', 'failed_count', 'last_contact_id', 'finished_at',
])]
class AudienceBroadcast extends Model
{
    use BelongsToOrganization;

    /** Everyone who has confirmed. */
    public const SEGMENT_ALL = 'all';

    /** Everyone who bought one particular product. */
    public const SEGMENT_PRODUCT = 'product';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'last_contact_id' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
