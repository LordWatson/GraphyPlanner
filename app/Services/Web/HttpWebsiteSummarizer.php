<?php

namespace App\Services\Web;

use App\Contracts\WebsiteSummarizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step B-2.4 — fetches a brand's own website and strips it to readable text for the AI quick-start.
 *
 * SSRF is the real risk here: the URL comes from an untrusted, self-serve signup, so a hostile
 * value could try to make the server fetch an internal address (cloud metadata, a private service).
 * Guards, in order:
 *  - only http/https URLs with a real host are accepted;
 *  - the host is resolved and every resulting IP is rejected if it falls in a private, loopback,
 *    link-local or otherwise reserved range (so `localhost`, `169.254.169.254`, `10.x`, `::1` and a
 *    hostname that resolves to one are all refused) — a host we can't resolve is also refused;
 *  - redirects are not followed (a public URL can't bounce us onto a private one);
 *  - the download is time- and size-capped, and only HTML/text is read.
 *
 * Any failure returns `null` (never throws): the quick-start then drafts from the typed facts alone
 * or falls back to an empty, editable form.
 */
class HttpWebsiteSummarizer implements WebsiteSummarizer
{
    private const TIMEOUT_SECONDS = 5;

    private const MAX_BYTES = 524288; // 512 KB of HTML is far more than enough to summarise.

    private const MAX_CHARS = 4000;

    public function summarize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($host === null || ! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('WebsiteSummarizer rejected a malformed URL', ['url' => $url]);

            return null;
        }

        if (! $this->hostIsPublic($host)) {
            Log::warning('WebsiteSummarizer blocked a non-public host (SSRF guard)', ['host' => $host]);

            return null;
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'AlimaBot/1.0 (+brand-profile-quickstart)'])
                ->accept('text/html,application/xhtml+xml,text/plain')
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('WebsiteSummarizer fetch failed', ['host' => $host, 'exception' => $e::class]);

            return null;
        }

        if (! $response->ok()) {
            Log::info('WebsiteSummarizer got a non-200 response', ['host' => $host, 'status' => $response->status()]);

            return null;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'text')) {
            return null;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }

        return $this->readable($body);
    }

    /**
     * True only when every address the host resolves to is a public, routable IP. A literal private
     * IP, a hostname that resolves to one, and a host that resolves to nothing are all refused.
     */
    private function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]');
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }

            if ($ips === []) {
                $resolved = @gethostbyname($host);

                if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                    $ips = [$resolved];
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function readable(string $html): ?string
    {
        // Drop the parts that carry no readable copy before stripping tags.
        $html = preg_replace('~<(script|style|noscript|template|svg|head)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
