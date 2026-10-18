<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Step I-1.9 — the tenant-isolation safety net. Every query on an org-owned model (see
 * `BelongsToOrganization`) is limited to the signed-in user's organization, so a query that
 * forgets `where('org_id', …)` can't return another tenant's rows.
 *
 * Not applied when there is no signed-in organization member: queue workers, scheduled
 * commands, the Upload-Post webhook, the token-based review/invite pages, and platform super
 * admins (who are org-less and work across organizations). Code that must cross organizations
 * on purpose calls `withoutOrgScope()`.
 *
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $orgId = self::currentOrgId();

        if ($orgId !== null) {
            $builder->where($model->qualifyColumn('org_id'), $orgId);
        }
    }

    public static function currentOrgId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return null;
        }

        return $user->org_id;
    }
}
