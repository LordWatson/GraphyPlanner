<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Phase CG-2 (Step CG-2.6) — the read/write seam for platform-wide super-admin settings. Values are
 * cached forever and the cache is dropped on write. Stored wrapped as `['value' => …]` so a scalar
 * (e.g. the billing boolean) round-trips cleanly through the JSON column.
 */
class PlatformSettings
{
    public const BILLING_ENABLED = 'billing.enabled';

    private const CACHE_PREFIX = 'platform-setting:';

    public function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key) {
            $row = PlatformSetting::query()->where('key', $key)->first();

            // Wrap so a cached "nothing stored" is distinguishable from a stored null.
            return ['stored' => $row !== null, 'value' => $row?->value['value'] ?? null];
        });

        return $value['stored'] ? $value['value'] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * The global Stripe billing switch. Defaults OFF — billing ships dark until a super admin turns
     * it on (and real Stripe prices exist).
     */
    public function billingEnabled(): bool
    {
        return (bool) $this->get(self::BILLING_ENABLED, false);
    }

    public function setBillingEnabled(bool $enabled): void
    {
        $this->set(self::BILLING_ENABLED, $enabled);
    }
}
