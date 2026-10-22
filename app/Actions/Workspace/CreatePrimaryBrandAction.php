<?php

namespace App\Actions\Workspace;

use App\Enums\ClientStatus;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step B-1.2 — gives a business workspace its single primary brand: an active `Client` row flagged
 * `is_primary` plus an empty `BrandBrain` for it to fill, created together so a half-built brand can
 * never exist. The database's unique index on the generated `primary_org_id` column guarantees one
 * primary brand per organization; this Action is the only supported way to create one.
 *
 * Reused by the business signup (Step B-2.1), which calls it inside the same transaction as
 * `RegisterOrganizationAction`.
 */
class CreatePrimaryBrandAction
{
    public function __invoke(Organization $organization, ?string $name = null, ?string $defaultLanguage = null): Client
    {
        return DB::transaction(function () use ($organization, $name, $defaultLanguage) {
            $client = Client::create([
                'org_id' => $organization->id,
                'name' => trim($name ?? '') !== '' ? trim((string) $name) : $organization->name,
                'status' => ClientStatus::Active,
                'is_primary' => true,
                'default_language' => $defaultLanguage,
            ]);

            // Every brand gets a Brand Brain; the business "Brand profile" editor (Step B-2.4 / B-4.1)
            // fills it. Created empty so onboarding has somewhere to write without a null check.
            BrandBrain::create(['client_id' => $client->id]);

            Log::info('Primary brand created', [
                'org_id' => $organization->id,
                'client_id' => $client->id,
            ]);

            return $client;
        });
    }
}
