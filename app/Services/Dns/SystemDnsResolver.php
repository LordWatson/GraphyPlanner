<?php

namespace App\Services\Dns;

use App\Contracts\DnsResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step GTM-3.2 — the real resolver, over PHP's `dns_get_record`. Every lookup is wrapped: a DNS
 * failure is a fact about the domain, never an exception for the caller to handle.
 */
class SystemDnsResolver implements DnsResolver
{
    /**
     * @return list<string>
     */
    public function cnameTargets(string $hostname): array
    {
        return array_values(array_map(
            fn (array $record): string => Str::lower(rtrim((string) ($record['target'] ?? ''), '.')),
            array_filter($this->lookup($hostname, DNS_CNAME), fn (array $record) => filled($record['target'] ?? null)),
        ));
    }

    /**
     * @return list<string>
     */
    public function txtRecords(string $hostname): array
    {
        return array_values(array_map(
            fn (array $record): string => (string) ($record['txt'] ?? ''),
            array_filter($this->lookup($hostname, DNS_TXT), fn (array $record) => filled($record['txt'] ?? null)),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookup(string $hostname, int $type): array
    {
        $records = @dns_get_record($hostname, $type);

        if ($records === false) {
            Log::info('DNS lookup failed', ['hostname' => $hostname, 'type' => $type]);

            return [];
        }

        return array_values($records);
    }
}
