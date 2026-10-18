<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Step I-1.9 — applied to every org-owned model (`org_id` column). Adds `OrganizationScope` so
 * queries only ever see the signed-in member's organization, plus `withoutOrgScope()` for the few
 * places that cross organizations on purpose.
 *
 * Route-model binding deliberately resolves *without* the scope: a bound model from another
 * organization still reaches its policy, which answers 403 — the policies stay the authority for
 * single records (and `CrossTenantRouteTest` proves every bound route refuses another tenant's
 * model), while the scope protects every list and lookup query.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithoutOrgScope(Builder $query): void
    {
        $query->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery(
            $this->newQueryWithoutScope(OrganizationScope::class),
            $value,
            $field,
        )->first();
    }

    /**
     * Same as above for `->withTrashed()` routes (soft-deletable models).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveSoftDeletableRouteBinding($value, $field = null): ?Model
    {
        // Lifts both global scopes these models carry: the org scope (the policy decides) and
        // soft deletes (the route asked for trashed models).
        return $this->resolveRouteBindingQuery($this->newQueryWithoutScopes(), $value, $field)->first();
    }
}
