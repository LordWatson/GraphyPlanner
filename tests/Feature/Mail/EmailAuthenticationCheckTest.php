<?php

namespace Tests\Feature\Mail;

use App\Contracts\DnsResolver;
use App\Services\Mail\EmailAuthenticationCheck;
use Tests\TestCase;

/**
 * `mail:check-dns` — whether the sending domain is authenticated.
 *
 * DNS is faked, because the real resolver sits behind `DnsResolver` precisely so these never touch
 * the network. What is being proved is that each of the three records is judged on its own, and
 * that the two configurations receivers actually punish — no DKIM at all, and two SPF records —
 * are reported rather than glossed over.
 */
class EmailAuthenticationCheckTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $txt
     */
    private function fakeDns(array $txt): void
    {
        $this->app->instance(DnsResolver::class, new class($txt) implements DnsResolver
        {
            /**
             * @param  array<string, list<string>>  $txt
             */
            public function __construct(private readonly array $txt) {}

            public function cnameTargets(string $hostname): array
            {
                return [];
            }

            public function txtRecords(string $hostname): array
            {
                return $this->txt[strtolower($hostname)] ?? [];
            }
        });
    }

    private function authenticated(): array
    {
        return [
            'mail.alima.test' => ['v=spf1 include:_spf.resend.com ~all'],
            'resend._domainkey.mail.alima.test' => ['v=DKIM1; k=rsa; p=MIIBIjANBg'],
            '_dmarc.mail.alima.test' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@alima.test'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.address' => 'hello@mail.alima.test', 'mail.dkim_selector' => 'resend']);
    }

    public function test_a_fully_authenticated_domain_passes(): void
    {
        $this->fakeDns($this->authenticated());

        $result = app(EmailAuthenticationCheck::class)->check();

        $this->assertTrue($result['ok']);
        $this->assertSame('mail.alima.test', $result['domain']);
        $this->artisan('mail:check-dns')->assertSuccessful();
    }

    public function test_a_domain_with_no_records_fails_every_check(): void
    {
        $this->fakeDns([]);

        $result = app(EmailAuthenticationCheck::class)->check();

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['spf']['ok']);
        $this->assertFalse($result['dkim']['ok']);
        $this->assertFalse($result['dmarc']['ok']);

        // Non-zero, so it can be a step in a release checklist.
        $this->artisan('mail:check-dns')->assertFailed();
    }

    public function test_missing_dkim_alone_fails(): void
    {
        $records = $this->authenticated();
        unset($records['resend._domainkey.mail.alima.test']);
        $this->fakeDns($records);

        $result = app(EmailAuthenticationCheck::class)->check();

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['spf']['ok']);
        $this->assertFalse($result['dkim']['ok']);
        $this->assertStringContainsString('resend._domainkey.mail.alima.test', $result['dkim']['name']);
    }

    /**
     * Two SPF records is a permanent failure at the receiver, not a harmless duplicate — so it must
     * not be reported as a pass just because one of them is correct.
     */
    public function test_two_spf_records_are_reported_as_a_failure(): void
    {
        $records = $this->authenticated();
        $records['mail.alima.test'] = ['v=spf1 include:_spf.resend.com ~all', 'v=spf1 include:other ~all'];
        $this->fakeDns($records);

        $result = app(EmailAuthenticationCheck::class)->check();

        $this->assertFalse($result['spf']['ok']);
        $this->assertStringContainsString('More than one', $result['spf']['detail']);
    }

    public function test_a_dmarc_policy_of_none_passes_with_a_nudge(): void
    {
        $records = $this->authenticated();
        $records['_dmarc.mail.alima.test'] = ['v=DMARC1; p=none; rua=mailto:dmarc@alima.test'];
        $this->fakeDns($records);

        $result = app(EmailAuthenticationCheck::class)->check();

        // p=none is the correct first step — it only asks for reports — so it is not a failure.
        $this->assertTrue($result['dmarc']['ok']);
        $this->assertStringContainsString('p=none', $result['dmarc']['detail']);
        $this->assertStringContainsString('quarantine', $result['dmarc']['detail']);
    }

    public function test_unrelated_txt_records_on_the_same_name_are_ignored(): void
    {
        $records = $this->authenticated();
        $records['mail.alima.test'] = [
            'google-site-verification=abc',
            'v=spf1 include:_spf.resend.com ~all',
        ];
        $this->fakeDns($records);

        $this->assertTrue(app(EmailAuthenticationCheck::class)->check()['spf']['ok']);
    }

    public function test_the_domain_comes_from_the_configured_from_address(): void
    {
        $this->fakeDns($this->authenticated());

        $this->assertSame('mail.alima.test', EmailAuthenticationCheck::sendingDomain());

        config(['mail.from.address' => 'not-an-address']);
        $this->assertSame('', EmailAuthenticationCheck::sendingDomain());

        // Nothing to check, and it says so rather than reporting three passes on an empty name.
        $this->artisan('mail:check-dns')->assertFailed();
    }

    public function test_an_explicit_domain_overrides_the_configured_one(): void
    {
        $this->fakeDns(['news.alima.test' => ['v=spf1 include:_spf.resend.com ~all']]);

        $result = app(EmailAuthenticationCheck::class)->check('News.Alima.Test');

        $this->assertSame('news.alima.test', $result['domain']);
        $this->assertTrue($result['spf']['ok']);
    }
}
