<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Step GTM-7.3 — somebody who sends us customers. No `OrganizationScope`: a partner belongs to the
 * platform, not to any one organization, and only a super admin touches them.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $email
 * @property int $commission_percent
 * @property string|null $payout_details
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Organization> $organizations
 * @property-read Collection<int, ReferralPayout> $payouts
 */
#[Fillable(['code', 'name', 'email', 'commission_percent', 'payout_details', 'ended_at'])]
class ReferralPartner extends Model
{
    /**
     * How long a referral click is remembered. A month is long enough to cover a trial and a
     * think-about-it weekend, and short enough that a commission is plausibly connected to the
     * click that earned it.
     */
    public const COOKIE_DAYS = 30;

    public const COOKIE = 'referral_code';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ended_at' => 'datetime', 'commission_percent' => 'integer'];
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'referral_partner_id');
    }

    /**
     * @return HasMany<ReferralPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(ReferralPayout::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Codes are typed by hand off a blog post, so they are matched case- and space-insensitively
     * and stored in the normalised form the unique index is on.
     */
    public static function normaliseCode(string $code): string
    {
        return Str::lower(trim(preg_replace('/[^A-Za-z0-9._-]/', '', $code) ?? ''));
    }

    public static function findByCode(string $code): ?self
    {
        $code = self::normaliseCode($code);

        return $code === '' ? null : self::query()->where('code', $code)->whereNull('ended_at')->first();
    }
}
