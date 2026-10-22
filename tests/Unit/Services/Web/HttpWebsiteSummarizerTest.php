<?php

namespace Tests\Unit\Services\Web;

use App\Services\Web\HttpWebsiteSummarizer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step B-2.4 — the SSRF guard and readability of the brand-website summarizer.
 */
class HttpWebsiteSummarizerTest extends TestCase
{
    public function test_it_strips_a_public_page_to_readable_text(): void
    {
        Http::fake([
            '*' => Http::response(
                '<html><head><title>Bakery</title><style>.a{color:red}</style></head>'
                .'<body><h1>Sourdough &amp; Co.</h1><script>alert(1)</script>'
                .'<p>Fresh bread daily in Bristol.</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        // A literal public IP avoids any DNS lookup in the test environment.
        $text = (new HttpWebsiteSummarizer)->summarize('http://93.184.216.34/');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Fresh bread daily in Bristol.', $text);
        $this->assertStringContainsString('Sourdough & Co.', $text);
        // Scripts and styles are dropped, not inlined as text.
        $this->assertStringNotContainsString('alert(1)', $text);
        $this->assertStringNotContainsString('color:red', $text);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'loopback ipv4' => ['http://127.0.0.1/'],
            'loopback name' => ['http://localhost/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/admin'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_it_blocks_private_loopback_and_link_local_addresses(string $url): void
    {
        Http::fake();

        $this->assertNull((new HttpWebsiteSummarizer)->summarize($url));

        // The SSRF guard refuses before any request leaves the server.
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_non_http_scheme_and_an_empty_url(): void
    {
        Http::fake();

        $summarizer = new HttpWebsiteSummarizer;

        $this->assertNull($summarizer->summarize('ftp://example.com/file'));
        $this->assertNull($summarizer->summarize('   '));

        Http::assertNothingSent();
    }

    public function test_it_returns_null_when_the_page_is_not_html(): void
    {
        Http::fake([
            '*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertNull((new HttpWebsiteSummarizer)->summarize('http://93.184.216.34/'));
    }

    public function test_it_returns_null_on_a_failed_response(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertNull((new HttpWebsiteSummarizer)->summarize('http://93.184.216.34/'));
    }
}
