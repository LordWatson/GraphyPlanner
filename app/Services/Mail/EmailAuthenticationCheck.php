<?php

namespace App\Services\Mail;

use App\Contracts\DnsResolver;

/**
 * Reads the DNS that decides whether our email arrives: SPF, DKIM and DMARC on the sending domain.
 *
 * Why this exists rather than a note in a runbook: the agency research named email landing in spam
 * as *the* biggest cause of approval delay, and every clever thing in the approval flow — token
 * links that need no login, reminder nudges, SLA tracking — depends on one email arriving. An
 * unauthenticated domain puts approval requests in the promotions tab and nobody finds out from
 * the product; they find out from a client who says they never got it.
 *
 * Reuses the `DnsResolver` contract built for bio-page custom domains, so this needs no new seam
 * and is testable without touching the network. That contract never throws: a failed lookup looks
 * like a missing record, which is the same thing to act on.
 *
 * @phpstan-type RecordResult array{ok: bool, name: string, found: list<string>, detail: string}
 */
class EmailAuthenticationCheck
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Check the domain we actually send from (`mail.from.address`), or one given explicitly.
     *
     * @return array{domain: string, ok: bool, spf: RecordResult, dkim: RecordResult, dmarc: RecordResult}
     */
    public function check(?string $domain = null): array
    {
        $domain = strtolower(trim($domain ?? self::sendingDomain()));

        $spf = $this->spf($domain);
        $dkim = $this->dkim($domain);
        $dmarc = $this->dmarc($domain);

        return [
            'domain' => $domain,
            'ok' => $spf['ok'] && $dkim['ok'] && $dmarc['ok'],
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
        ];
    }

    /**
     * The domain part of the configured From address — what receivers actually judge.
     */
    public static function sendingDomain(): string
    {
        $from = (string) config('mail.from.address');

        return str_contains($from, '@') ? strtolower(substr($from, strpos($from, '@') + 1)) : '';
    }

    /**
     * SPF: one `v=spf1` record on the domain itself. More than one is a misconfiguration receivers
     * treat as a permanent error, so it is reported rather than tolerated.
     *
     * @return RecordResult
     */
    private function spf(string $domain): array
    {
        $records = $this->matching($domain, 'v=spf1');

        return match (count($records)) {
            0 => $this->fail($domain, [], 'No v=spf1 record. Receivers cannot tell that our mail provider may send for this domain.'),
            1 => $this->pass($domain, $records, 'SPF published.'),
            default => $this->fail($domain, $records, 'More than one v=spf1 record — receivers treat that as a permanent failure. Merge them into one.'),
        };
    }

    /**
     * DKIM: the selector our provider publishes under. Configurable because it is provider-specific
     * (`resend._domainkey` by default) and a second sending domain may use another.
     *
     * @return RecordResult
     */
    private function dkim(string $domain): array
    {
        $selector = (string) config('mail.dkim_selector', 'resend');
        $host = "{$selector}._domainkey.{$domain}";
        $records = $this->matching($host, 'v=dkim1', 'p=');

        return $records === []
            ? $this->fail($host, [], "No DKIM key at {$host}. Unsigned mail is the single biggest reason an approval request lands in spam.")
            : $this->pass($host, $records, 'DKIM key published.');
    }

    /**
     * DMARC: a policy at `_dmarc`. A published `p=none` is reported as a pass with a nudge — it is
     * the correct first step (it only asks for reports), and moving to quarantine before reading
     * those reports is how legitimate mail gets dropped.
     *
     * @return RecordResult
     */
    private function dmarc(string $domain): array
    {
        $host = "_dmarc.{$domain}";
        $records = $this->matching($host, 'v=dmarc1');

        if ($records === []) {
            return $this->fail($host, [], "No DMARC policy at {$host}. Without one, SPF and DKIM are advisory and nobody reports what is failing.");
        }

        $policy = $this->policyIn($records[0]);

        return $this->pass($host, $records, match ($policy) {
            'none' => 'DMARC published at p=none. Right place to start — read the reports for a couple of weeks, then move to quarantine.',
            'quarantine', 'reject' => "DMARC published at p={$policy}.",
            default => 'DMARC published, but with no p= policy, which receivers read as none.',
        });
    }

    /**
     * TXT records at `$host` containing every one of `$needles` (case-insensitively). DNS returns
     * TXT strings for many purposes on the same name, so matching on content is the only way to
     * find the one that was meant.
     *
     * @return list<string>
     */
    private function matching(string $host, string ...$needles): array
    {
        if ($host === '' || str_starts_with($host, '.')) {
            return [];
        }

        return array_values(array_filter(
            $this->dns->txtRecords($host),
            function (string $record) use ($needles): bool {
                $lower = strtolower($record);

                foreach ($needles as $needle) {
                    if (! str_contains($lower, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function policyIn(string $record): string
    {
        return preg_match('/\bp\s*=\s*([a-z]+)/i', $record, $matches) === 1
            ? strtolower($matches[1])
            : '';
    }

    /**
     * @param  list<string>  $found
     * @return RecordResult
     */
    private function pass(string $name, array $found, string $detail): array
    {
        return ['ok' => true, 'name' => $name, 'found' => $found, 'detail' => $detail];
    }

    /**
     * @param  list<string>  $found
     * @return RecordResult
     */
    private function fail(string $name, array $found, string $detail): array
    {
        return ['ok' => false, 'name' => $name, 'found' => $found, 'detail' => $detail];
    }
}
