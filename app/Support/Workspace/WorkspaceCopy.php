<?php

namespace App\Support\Workspace;

use App\Enums\OrganizationType;
use App\Models\Organization;

/**
 * Step B-4.4 — translates agency vocabulary for a business workspace in PHP-rendered surfaces
 * (mails, notifications), the counterpart of the frontend `resources/js/lib/workspace-copy.ts`. A
 * business user never reads "client", "retainer" or "invoice"; their brand is "your brand".
 */
final class WorkspaceCopy
{
    /**
     * @var array<string, string>
     */
    private const BUSINESS_TERMS = [
        'client' => 'brand',
        'Client' => 'Brand',
        'clients' => 'brands',
        'Clients' => 'Brands',
        'campaign' => 'collection',
        'Campaign' => 'Collection',
        'campaigns' => 'collections',
        'Campaigns' => 'Collections',
        'internal review' => 'review',
        'Internal review' => 'Review',
    ];

    public static function forOrganization(?Organization $organization): self
    {
        return new self(
            $organization instanceof Organization ? $organization->type : OrganizationType::Agency,
        );
    }

    public function __construct(private readonly OrganizationType $type) {}

    public function isBusiness(): bool
    {
        return $this->type === OrganizationType::Business;
    }

    /**
     * Translate a single term for this workspace. Unknown terms pass through unchanged.
     */
    public function term(string $word): string
    {
        if (! $this->isBusiness()) {
            return $word;
        }

        return self::BUSINESS_TERMS[$word] ?? $word;
    }

    /**
     * The label for the entity a post belongs to: "client" (agency) or "brand" (business).
     */
    public function brandWord(): string
    {
        return $this->isBusiness() ? 'brand' : 'client';
    }
}
