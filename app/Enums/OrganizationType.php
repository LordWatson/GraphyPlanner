<?php

namespace App\Enums;

/**
 * Step B-1.1 — the shape of a workspace. An `agency` org is a studio that manages many
 * `Client` brands (today's product); a `business` org manages its own single brand with no agency
 * in between (see `.junie/plans/business-workspaces.md` §2). The value drives the shell
 * (routes, navigation, copy) and the per-type contracts (`CurrentWorkspace`, `PostWorkflow`,
 * `PublishCredentials`, `Entitlements`), never scattered `if` branches in the shared engine.
 */
enum OrganizationType: string
{
    case Agency = 'agency';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::Business => 'Business',
        };
    }
}
