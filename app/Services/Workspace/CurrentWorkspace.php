<?php

namespace App\Services\Workspace;

use App\Enums\OrganizationType;
use App\Exceptions\Workspace\WorkspaceMisconfiguredException;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Step B-1.3 — resolves the signed-in member's workspace once per request (bound `scoped` in
 * `AppServiceProvider`). Business controllers never take a `{client}` route parameter: they ask
 * this service for the brand and then call the same Actions agency controllers call, so the shared
 * engine stays keyed by `client_id` while the business shell hides the concept.
 */
class CurrentWorkspace
{
    private bool $brandResolved = false;

    private ?Client $brand = null;

    public function organization(): ?Organization
    {
        return Auth::user()?->organization;
    }

    public function type(): ?OrganizationType
    {
        return $this->organization()?->type;
    }

    public function isBusiness(): bool
    {
        return $this->organization()?->isBusiness() ?? false;
    }

    public function isAgency(): bool
    {
        return $this->organization()?->isAgency() ?? false;
    }

    /**
     * The business workspace's primary brand, cached for the request. Null for an agency (which has
     * no single brand). A `business` org with no primary brand is a broken invariant — logged and
     * surfaced as a `WorkspaceMisconfiguredException` rather than silently returning null.
     */
    public function brand(): ?Client
    {
        if ($this->brandResolved) {
            return $this->brand;
        }

        $this->brandResolved = true;

        $organization = $this->organization();

        if ($organization === null || ! $organization->isBusiness()) {
            return $this->brand = null;
        }

        $brand = $organization->primaryBrand()->first();

        if ($brand === null) {
            Log::error('Business workspace has no primary brand', ['org_id' => $organization->id]);

            throw new WorkspaceMisconfiguredException($organization->id);
        }

        return $this->brand = $brand;
    }

    /**
     * The primary brand's id, or null for an agency. Convenience for controllers that only need to
     * scope a query.
     */
    public function brandId(): ?int
    {
        return $this->brand()?->id;
    }
}
