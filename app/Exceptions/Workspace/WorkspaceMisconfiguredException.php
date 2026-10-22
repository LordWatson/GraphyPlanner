<?php

namespace App\Exceptions\Workspace;

use RuntimeException;

/**
 * Step B-1.3 — thrown when a `business` organization has no primary brand. That's an invariant
 * violation (`CreatePrimaryBrandAction` creates one with the org and the database enforces exactly
 * one), so reaching this means the data is broken, not that the user did something wrong. The
 * resolver logs `org_id` before throwing; the exception renders as a 500.
 */
class WorkspaceMisconfiguredException extends RuntimeException
{
    public function __construct(public readonly int $organizationId)
    {
        parent::__construct("Business organization {$organizationId} has no primary brand.");
    }
}
