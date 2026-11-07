<?php

namespace App\Contracts;

/**
 * Step GTM-3.2 — reads public DNS, so domain verification can be tested without touching the
 * network. Bound to `SystemDnsResolver` in `AppServiceProvider`; tests bind a fake.
 *
 * Implementations **never throw**: a lookup that fails for any reason (NXDOMAIN, a timeout, a
 * resolver that is down) returns an empty result, and the caller treats that as "not verified yet"
 * rather than as an error — which is also what it means to a creator who hasn't finished their DNS.
 */
interface DnsResolver
{
    /**
     * The CNAME targets for a hostname, lowercased and without the trailing dot. Empty when there
     * is no CNAME, or the lookup failed.
     *
     * @return list<string>
     */
    public function cnameTargets(string $hostname): array;

    /**
     * The TXT record strings for a hostname. Empty when there are none, or the lookup failed.
     *
     * @return list<string>
     */
    public function txtRecords(string $hostname): array;
}
