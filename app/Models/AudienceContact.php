<?php

namespace App\Models;

use App\Enums\AudienceSource;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step GTM-2.1 — one person on a brand's email list. Two dates carry the whole permission model:
 *
 *  - `consent_at` — set **only** by the person: clicking the confirmation link, or ticking the
 *    marketing box at checkout. Never set by an import, and never inferred from a purchase.
 *  - `unsubscribed_at` — set by the person and never cleared by us. It outranks consent, and it
 *    stops transactional follow-ups too, not just marketing.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $email
 * @property string|null $name
 * @property AudienceSource $source
 * @property Carbon|null $consent_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'client_id', 'email', 'name', 'source', 'consent_at', 'unsubscribed_at'])]
class AudienceContact extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AudienceSource::class,
            'consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
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

    /**
     * May we send this person anything at all? An unsubscribe is the one answer that is always no.
     */
    public function isMailable(): bool
    {
        return $this->unsubscribed_at === null;
    }

    /**
     * May we send this person marketing — a welcome, or a broadcast? Only if they said so
     * themselves and haven't since unsubscribed.
     */
    public function isMarketable(): bool
    {
        return $this->consent_at !== null && $this->unsubscribed_at === null;
    }

    /**
     * Everyone who may receive a broadcast. The same rule as {@see self::isMarketable()}, in SQL.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function marketable(Builder $query): void
    {
        $query->whereNotNull('consent_at')->whereNull('unsubscribed_at');
    }
}
