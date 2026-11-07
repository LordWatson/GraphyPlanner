<?php

namespace Tests\Feature\Business;

use App\Actions\Business\ReverifyBioPageDomainsAction;
use App\Contracts\DnsResolver;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\BioPage;
use App\Models\BioPageDomain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step GTM-3.2 — a creator's own hostname for their bio page. DNS is faked (the real resolver is
 * behind `DnsResolver` precisely so these tests never touch the network), and the thing being
 * proved throughout is that **only a verified domain serves anything** and the `@handle` address
 * never stops working.
 */
class BioPageDomainTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $brand;

    private User $owner;

    private BioPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
        ]);
        $this->brand = Client::factory()->for($this->org, 'organization')->primary()->create(['name' => 'Jess Bakery']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->page = BioPage::create([
            'org_id' => $this->org->id,
            'client_id' => $this->brand->id,
            'handle' => 'jessbakery',
            'theme' => 'cream',
            'published_at' => now(),
        ]);

        config(['bio.domain_target' => 'links.alima.test']);
    }

    /**
     * @param  array<string, list<string>>  $cnames
     * @param  array<string, list<string>>  $txt
     */
    private function fakeDns(array $cnames = [], array $txt = []): void
    {
        $this->app->instance(DnsResolver::class, new class($cnames, $txt) implements DnsResolver
        {
            /**
             * @param  array<string, list<string>>  $cnames
             * @param  array<string, list<string>>  $txt
             */
            public function __construct(private array $cnames, private array $txt) {}

            public function cnameTargets(string $hostname): array
            {
                return $this->cnames[$hostname] ?? [];
            }

            public function txtRecords(string $hostname): array
            {
                return $this->txt[$hostname] ?? [];
            }
        });
    }

    private function add(string $hostname = 'links.jessbakery.test'): BioPageDomain
    {
        $this->actingAs($this->owner)
            ->post(route('business.bio.domains.store'), ['hostname' => $hostname])
            ->assertRedirect();

        return BioPageDomain::query()
            ->where('hostname', BioPageDomain::normaliseHostname($hostname))
            ->sole();
    }

    /**
     * `CurrentWorkspace` is a scoped binding, so inside one test it memoises the first request's
     * brand. Real requests each get a fresh container; a test that signs in as somebody else has
     * to say so.
     */
    private function signOutOfWorkspace(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_a_domain_whose_cname_points_here_verifies_and_serves_the_page(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);

        $domain = $this->add();
        $this->assertTrue($domain->isVerified());

        // The host is the whole address: the root of that hostname is the creator's page.
        $this->get('http://links.jessbakery.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/bio')->where('page.handle', 'jessbakery'));

        // And the canonical handle address still works, on our own host.
        $this->get(route('bio.show', 'jessbakery'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/bio'));
    }

    public function test_an_apex_domain_can_prove_itself_with_a_txt_record_instead(): void
    {
        $this->fakeDns();
        $domain = $this->add('jessbakery.test');
        $this->assertFalse($domain->isVerified());

        $this->fakeDns(txt: ['jessbakery.test' => ['v=spf1 -all', $domain->verification_token]]);

        $this->actingAs($this->owner)
            ->post(route('business.bio.domains.verify', $domain))
            ->assertRedirect();

        $this->assertTrue($domain->refresh()->isVerified());
    }

    public function test_a_domain_that_does_not_point_here_never_serves_and_says_why(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['someone-elses-host.test']]);

        $domain = $this->add();

        $this->assertFalse($domain->isVerified());
        $this->assertStringContainsString('someone-elses-host.test', (string) $domain->last_error);

        // An unverified claim serves nothing: the root of that host is still our marketing page.
        $this->get('http://links.jessbakery.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('welcome'));
    }

    public function test_our_own_host_always_serves_the_marketing_page(): void
    {
        // Even with a verified domain row that somehow carries our own hostname.
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $this->fakeDns([$host => ['links.alima.test']]);

        $this->page->domains()->create([
            'hostname' => $host,
            'verification_token' => BioPageDomain::newToken(),
            'verified_at' => now(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('welcome'));
    }

    public function test_the_nightly_sweep_takes_a_repointed_domain_offline_and_brings_a_ready_one_up(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);
        $live = $this->add();
        $this->assertTrue($live->isVerified());

        $waiting = $this->page->domains()->create([
            'hostname' => 'shop.jessbakery.test',
            'verification_token' => BioPageDomain::newToken(),
        ]);

        // Overnight: the first is repointed away, the second's DNS propagates.
        $this->fakeDns(['shop.jessbakery.test' => ['links.alima.test']]);

        $result = app(ReverifyBioPageDomainsAction::class)();

        $this->assertSame(['checked' => 2, 'lost' => 1, 'gained' => 1], $result);
        $this->assertFalse($live->refresh()->isVerified());
        $this->assertTrue($waiting->refresh()->isVerified());

        // The repointed host stops resolving to this page at once.
        $this->get('http://links.jessbakery.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('welcome'));
    }

    public function test_the_artisan_command_runs_the_sweep(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);
        $this->add();

        $this->fakeDns();
        $this->artisan('bio-domains:reverify')->assertSuccessful();

        $this->assertFalse(BioPageDomain::query()->sole()->isVerified());
    }

    public function test_a_plan_without_the_gate_cannot_add_a_domain(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);
        $this->org->forceFill(['plan' => 'business_free'])->save();

        $this->actingAs($this->owner)
            ->post(route('business.bio.domains.store'), ['hostname' => 'links.jessbakery.test']);

        $this->assertSame(0, BioPageDomain::query()->count());
    }

    public function test_a_hostname_is_normalised_claimed_once_and_capped(): void
    {
        $this->fakeDns();

        $domain = $this->add('HTTPS://Links.JessBakery.test:443/path');
        $this->assertSame('links.jessbakery.test', $domain->hostname);

        // Nobody else can claim it, not even another brand.
        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        BioPage::create([
            'org_id' => $otherOrg->id,
            'client_id' => $otherBrand->id,
            'handle' => 'otherbakery',
            'theme' => 'cream',
        ]);

        $this->signOutOfWorkspace();
        $this->actingAs($otherOwner)
            ->post(route('business.bio.domains.store'), ['hostname' => 'links.jessbakery.test'])
            ->assertSessionHasErrors('hostname');

        // And one page is capped.
        $this->signOutOfWorkspace();
        foreach (['a.jessbakery.test', 'b.jessbakery.test'] as $hostname) {
            $this->add($hostname);
        }

        $this->actingAs($this->owner)
            ->post(route('business.bio.domains.store'), ['hostname' => 'c.jessbakery.test'])
            ->assertSessionHasErrors('hostname');

        $this->assertSame(BioPageDomain::MAX_PER_PAGE, $this->page->domains()->count());
    }

    public function test_another_brands_domain_cannot_be_verified_or_removed_and_removing_keeps_the_handle(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);
        $domain = $this->add();

        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $this->signOutOfWorkspace();
        $this->actingAs($otherOwner)->post(route('business.bio.domains.verify', $domain))->assertNotFound();
        $this->actingAs($otherOwner)->delete(route('business.bio.domains.destroy', $domain))->assertNotFound();
        $this->assertDatabaseHas('bio_page_domains', ['id' => $domain->id]);

        $this->signOutOfWorkspace();
        $this->actingAs($this->owner)->delete(route('business.bio.domains.destroy', $domain))->assertRedirect();
        $this->assertDatabaseMissing('bio_page_domains', ['id' => $domain->id]);

        // The handle namespace is canonical and is never affected by any of this.
        $this->get(route('bio.show', 'jessbakery'))->assertOk();
    }

    public function test_an_unpublished_page_serves_nothing_even_on_a_verified_domain(): void
    {
        $this->fakeDns(['links.jessbakery.test' => ['links.alima.test']]);
        $this->add();

        $this->page->update(['published_at' => null]);

        $this->get('http://links.jessbakery.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('welcome'));
    }
}
