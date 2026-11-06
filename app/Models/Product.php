<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.2 — something the creator sells from their bio page: a download, a booking, a tip jar
 * or a paid question ({@see ProductType}).
 *
 * Money is always minor units in one currency. `settings` carries the per-type payload so a new
 * type needs no migration:
 *
 *  - download: `download_limit`, `download_expiry_hours`
 *  - booking: `availability` (`weekdays`, `start`, `end`, `slot_minutes`, `buffer_minutes`,
 *    `lead_hours`, `days_ahead`)
 *  - tip: `min_minor`
 *  - qa: `answer_window_hours`
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property ProductType $type
 * @property string $title
 * @property string|null $description
 * @property int $price_minor
 * @property string $currency
 * @property ProductStatus $status
 * @property int|null $cover_asset_id
 * @property int|null $download_asset_id
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $sales_count  withCount alias (GetStoreOverviewAction)
 */
#[Fillable([
    'org_id', 'client_id', 'type', 'title', 'description', 'price_minor', 'currency', 'status',
    'cover_asset_id', 'download_asset_id', 'settings',
])]
class Product extends Model
{
    use BelongsToOrganization;

    /**
     * Stripe's own floor for a card charge (£0.30 / $0.50 in minor units). Anything under it is
     * refused at checkout rather than failing inside Stripe.
     */
    public const MIN_CHARGE_MINOR = 100;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'settings' => 'array',
            'price_minor' => 'integer',
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
     * @return BelongsTo<Asset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function downloadAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'download_asset_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isBuyable(): bool
    {
        return $this->status->isBuyable();
    }

    /**
     * One `settings` key with a default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function downloadLimit(): int
    {
        return max(1, (int) $this->setting('download_limit', 5));
    }

    public function downloadExpiryHours(): int
    {
        return max(1, (int) $this->setting('download_expiry_hours', 72));
    }

    /**
     * How long a paid question's authorisation is held. Capped at six days: Stripe lets a card
     * authorisation lapse after about seven, and a capture after that fails.
     */
    public function answerWindowHours(): int
    {
        return max(1, min((int) $this->setting('answer_window_hours', 72), 144));
    }

    public function publicUrl(): ?string
    {
        $handle = BioPage::query()->where('client_id', $this->client_id)->value('handle');

        return $handle === null ? null : route('store.product', ['handle' => $handle, 'product' => $this->id]);
    }
}
