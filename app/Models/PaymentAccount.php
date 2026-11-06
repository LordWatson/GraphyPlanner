<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.1 — the org's connected payment account (Stripe Connect Express today). It holds the
 * account id and the capability flags Stripe reports, never a key or secret: Alima authenticates
 * with its own platform key and names this account on each call.
 *
 * Kept entirely apart from Cashier's customer columns on `organizations` (Principle 4) — this is
 * the creator taking money, not the creator paying us.
 *
 * @property int $id
 * @property int $org_id
 * @property string $provider
 * @property string $external_account_id
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property list<string>|null $requirements_due
 * @property string|null $country
 * @property string|null $default_currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'provider', 'external_account_id', 'charges_enabled', 'payouts_enabled',
    'requirements_due', 'country', 'default_currency',
])]
class PaymentAccount extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'requirements_due' => 'array',
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
     * Ready to take money. Payouts can still be pending (Stripe often enables charges first), which
     * is fine — the money is the creator's either way.
     */
    public function canSell(): bool
    {
        return $this->charges_enabled;
    }
}
