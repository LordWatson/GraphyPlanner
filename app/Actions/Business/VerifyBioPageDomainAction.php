<?php

namespace App\Actions\Business;

use App\Contracts\DnsResolver;
use App\Models\BioPageDomain;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step GTM-3.2 — check whether a hostname really points here, and record the answer.
 *
 * A domain is verified when **either** proof holds:
 *
 *  - its CNAME points at `config('bio.domain_target')` — the record that actually makes the
 *    hostname serve the page, so this is the one that matters; or
 *  - a TXT record on the hostname contains the row's `verification_token`, which lets an apex
 *    domain (where a CNAME is illegal) be proved while an A record or ALIAS does the routing.
 *
 * **It runs in both directions.** The same Action is the "Verify" button and the nightly sweep, so
 * a domain that stops pointing here loses `verified_at` and stops serving at once — a repointed
 * hostname must not keep resolving to somebody else's page.
 */
class VerifyBioPageDomainAction
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function __invoke(BioPageDomain $domain): bool
    {
        $target = Str::lower((string) config('bio.domain_target'));
        $cnames = $this->dns->cnameTargets($domain->hostname);
        $txt = $this->dns->txtRecords($domain->hostname);

        $byCname = in_array($target, $cnames, true);
        $byToken = $txt !== [] && collect($txt)->contains(fn (string $value) => str_contains($value, $domain->verification_token));
        $verified = $byCname || $byToken;

        $domain->update([
            'verified_at' => $verified ? ($domain->verified_at ?? now()) : null,
            'last_checked_at' => now(),
            'last_error' => $verified ? null : $this->reason($cnames, $target),
            // We never issue the certificate (see `ops.md`); this only records what whatever
            // terminates TLS has reported. A domain that lapses goes back to pending.
            'ssl_status' => $verified ? ($domain->ssl_status ?: 'pending') : 'pending',
        ]);

        Log::info('Bio page domain checked', [
            'bio_page_domain_id' => $domain->id,
            'bio_page_id' => $domain->bio_page_id,
            'verified' => $verified,
            'by' => $verified ? ($byCname ? 'cname' : 'txt') : null,
        ]);

        return $verified;
    }

    /**
     * @param  list<string>  $cnames
     */
    private function reason(array $cnames, string $target): string
    {
        if ($cnames === []) {
            return "No CNAME found. Point it at {$target}.";
        }

        return 'CNAME points at '.implode(', ', array_slice($cnames, 0, 2)).", not {$target}.";
    }
}
