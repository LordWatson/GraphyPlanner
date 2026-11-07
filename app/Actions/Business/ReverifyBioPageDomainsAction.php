<?php

namespace App\Actions\Business;

use App\Models\BioPageDomain;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-3.2 — the nightly sweep. Every domain is re-checked, verified or not:
 *
 *  - a **verified** one that no longer points here loses `verified_at` and stops serving, which is
 *    the point of the sweep — a creator who moves their domain elsewhere must not keep somebody
 *    else's page on it;
 *  - an **unverified** one is retried, so a creator who sets their DNS and never comes back to
 *    press the button still goes live.
 *
 * Each domain is checked on its own; one failed lookup never stops the rest (the resolver doesn't
 * throw, by contract).
 */
class ReverifyBioPageDomainsAction
{
    public function __construct(private readonly VerifyBioPageDomainAction $verify) {}

    /**
     * @return array{checked: int, lost: int, gained: int}
     */
    public function __invoke(): array
    {
        $checked = 0;
        $lost = 0;
        $gained = 0;

        foreach (BioPageDomain::query()->orderBy('id')->cursor() as $domain) {
            $wasVerified = $domain->isVerified();
            $isVerified = ($this->verify)($domain);
            $checked++;

            if ($wasVerified && ! $isVerified) {
                $lost++;
                Log::warning('A verified bio page domain stopped pointing here', [
                    'bio_page_domain_id' => $domain->id,
                    'bio_page_id' => $domain->bio_page_id,
                    'hostname' => $domain->hostname,
                ]);
            }

            if (! $wasVerified && $isVerified) {
                $gained++;
            }
        }

        Log::info('Bio page domains re-verified', compact('checked', 'lost', 'gained'));

        return ['checked' => $checked, 'lost' => $lost, 'gained' => $gained];
    }
}
