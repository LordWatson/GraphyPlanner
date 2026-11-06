<?php

namespace App\Contracts;

use App\Enums\Feature;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Organization;
use App\Services\Plans\ConfigEntitlements;
use App\Support\Plans\Plan;

/**
 * Step CG-2.2 / B-9.1 — what an organization's plan lets it do. Implementations resolve the org's
 * effective {@see Plan} and answer in terms of {@see Feature}:
 *
 *  - {@see limit()} — the numeric cap for a limit feature (`null` = unlimited).
 *  - {@see allows()} — whether a gate feature is granted (or a limit feature has any headroom at all).
 *  - {@see ensure()} — the enforcement seam Actions call before a metered write; it throws a typed
 *    {@see PlanLimitReachedException} (rendered as a friendly upgrade prompt, never a 403).
 *
 * Only one implementation today ({@see ConfigEntitlements}), but it is bound as
 * an interface so a future usage-based or billing-driven source is a drop-in swap and so Actions
 * can be unit-tested against a fake.
 */
interface Entitlements
{
    public function plan(Organization $organization): Plan;

    public function limit(Organization $organization, Feature $feature): ?int;

    public function allows(Organization $organization, Feature $feature): bool;

    /**
     * Headroom left for a limit feature given current usage (`null` = unlimited). Returns `null` for
     * a gate feature.
     */
    public function remaining(Organization $organization, Feature $feature, int $used): ?int;

    /**
     * Assert the org may perform one more of `$feature` given `$current` existing usage. Throws
     * {@see PlanLimitReachedException} when a gate feature isn't granted, or a limit feature's cap
     * has been reached.
     *
     * @throws PlanLimitReachedException
     */
    public function ensure(Organization $organization, Feature $feature, int $current = 0): void;
}
