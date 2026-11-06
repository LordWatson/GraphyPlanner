<?php

namespace App\Models;

use App\Actions\Deals\GetBrandDealsAction;
use App\Enums\DealStatus;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step CG-5.2 (B-11.2) — one sponsor relationship for the brand: what was agreed, what it pays, and
 * which posts deliver it. Progress is never stored: it is counted from the deal's own **published**
 * posts ({@see GetBrandDealsAction}), so the tracker can't drift from what
 * actually went out.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $partner_name
 * @property string|null $partner_handle
 * @property DealStatus $status
 * @property int|null $fee_minor
 * @property string|null $currency
 * @property list<array{platform: string, quantity: int}>|null $deliverables
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property string|null $notes
 * @property string|null $contact_email
 * @property bool $show_on_media_kit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Post> $posts
 */
#[Fillable([
    'org_id', 'client_id', 'partner_name', 'partner_handle', 'status', 'fee_minor', 'currency',
    'deliverables', 'starts_on', 'due_on', 'notes', 'contact_email', 'show_on_media_kit',
])]
class BrandDeal extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'deliverables' => 'array',
            'starts_on' => 'date',
            'due_on' => 'date',
            'show_on_media_kit' => 'boolean',
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
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The agreed deliverables, cleaned: a real platform, at least one post, one row per platform.
     *
     * @return list<array{platform: Platform, quantity: int}>
     */
    public function deliverableTargets(): array
    {
        $targets = [];

        foreach ($this->deliverables ?? [] as $deliverable) {
            $platform = Platform::tryFrom((string) $deliverable['platform']);
            $quantity = max(1, (int) $deliverable['quantity']);

            if ($platform !== null && ! isset($targets[$platform->value])) {
                $targets[$platform->value] = ['platform' => $platform, 'quantity' => $quantity];
            }
        }

        return array_values($targets);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && $this->status->isOpen() && $this->due_on->isPast();
    }
}
