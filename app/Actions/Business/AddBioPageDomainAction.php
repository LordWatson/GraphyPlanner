<?php

namespace App\Actions\Business;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\BioPage;
use App\Models\BioPageDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step GTM-3.2 — claim a hostname for a bio page. The row is created **unverified**, so claiming a
 * name serves nothing until DNS actually points here ({@see VerifyBioPageDomainAction}), and the
 * first verification attempt runs straight away so the creator sees what is still missing.
 *
 * Three refusals: the plan gate, the per-page ceiling, and a hostname somebody else already has
 * (`hostname` is globally unique — the database is the real guard, this is the friendly message).
 */
class AddBioPageDomainAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly VerifyBioPageDomainAction $verify,
    ) {}

    public function __invoke(BioPage $page, string $hostname): BioPageDomain
    {
        $organization = $page->client?->organization;

        if ($organization !== null) {
            $this->entitlements->ensure($organization, Feature::CustomDomain);
        }

        $hostname = BioPageDomain::normaliseHostname($hostname);

        if ($page->domains()->count() >= BioPageDomain::MAX_PER_PAGE) {
            throw ValidationException::withMessages([
                'hostname' => __('You can point up to :count domains at one page.', ['count' => BioPageDomain::MAX_PER_PAGE]),
            ]);
        }

        if (BioPageDomain::query()->where('hostname', $hostname)->exists()) {
            throw ValidationException::withMessages([
                'hostname' => __('That domain is already in use.'),
            ]);
        }

        $domain = DB::transaction(fn (): BioPageDomain => $page->domains()->create([
            'hostname' => $hostname,
            'verification_token' => BioPageDomain::newToken(),
        ]));

        Log::info('Bio page domain added', [
            'org_id' => $page->org_id,
            'bio_page_id' => $page->id,
            'bio_page_domain_id' => $domain->id,
        ]);

        // Outside the transaction: a DNS lookup is external work, and it never throws.
        ($this->verify)($domain);

        return $domain->refresh();
    }
}
