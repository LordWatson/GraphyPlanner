<?php

namespace Tests\Feature\Marketing;

use App\Actions\SuperAdmin\GetFunnelMetricsAction;
use App\Contracts\OpsAlerter;
use App\Enums\OrderStatus;
use App\Enums\OrganizationType;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Models\BioPage;
use App\Models\Client;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Product;
use App\Models\ReferralPartner;
use App\Models\ReferralPayout;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase GTM-7 — launch mechanics: the funnel figures (7.1), the quarterly competitor review (7.2),
 * the comparison pages and the referral programme (7.3), and the agent-access page (7.4).
 */
class LaunchMechanicsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => Role::SuperAdmin, 'org_id' => null]);
    }

    // ---------------------------------------------------------------- GTM-7.1

    public function test_the_funnel_reports_null_rather_than_zero_percent_on_an_empty_install(): void
    {
        $funnel = app(GetFunnelMetricsAction::class)();

        // A new install has no trials, so it has no conversion rate. 0% would read as "nobody
        // converts", which is a different and false claim.
        $this->assertNull($funnel['conversion']['rate']);
        $this->assertNull($funnel['time_to_first_post']['median_hours']);
        $this->assertNull($funnel['bio_pages']['rate']);
        $this->assertSame([], $funnel['store_gmv']['totals']);
        $this->assertSame(0, $funnel['store_gmv']['orders']);
    }

    public function test_the_funnel_measures_conversion_first_post_bio_pages_and_store_gmv(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'creator_pro',
            'trial_ends_at' => now()->addDays(14),
            'created_at' => now()->subDays(10),
        ]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();

        DB::table('subscriptions')->insert([
            'organization_id' => $org->id,
            'type' => 'default',
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BioPage::create([
            'org_id' => $org->id,
            'client_id' => $brand->id,
            'handle' => 'solobrand',
            'theme' => 'cream',
            'published_at' => now(),
        ]);

        $account = SocialAccount::factory()->for($brand)->create(['org_id' => $org->id]);
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Published]);
        PostTarget::factory()->for($post)->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'published_at' => now()->subDays(8),
        ]);

        $product = Product::create([
            'org_id' => $org->id,
            'client_id' => $brand->id,
            'type' => ProductType::Download,
            'title' => 'Guide',
            'price_minor' => 1200,
            'currency' => 'GBP',
            'status' => ProductStatus::Live,
        ]);
        Order::create([
            'org_id' => $org->id,
            'product_id' => $product->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 1200,
            'currency' => 'GBP',
            'status' => OrderStatus::Fulfilled,
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);

        $funnel = app(GetFunnelMetricsAction::class)();

        $this->assertSame(1, $funnel['conversion']['trials']);
        $this->assertSame(1, $funnel['conversion']['paid']);
        $this->assertSame(100.0, $funnel['conversion']['rate']);

        // Created 10 days ago, published 8 days ago → about two days.
        $this->assertNotNull($funnel['time_to_first_post']['median_hours']);
        $this->assertSame(1, $funnel['time_to_first_post']['published']);

        $this->assertSame(1, $funnel['bio_pages']['published']);
        $this->assertSame(100.0, $funnel['bio_pages']['rate']);

        // Store GMV comes from our own orders, per currency, never summed across them.
        $this->assertSame(1, $funnel['store_gmv']['orders']);
        $this->assertSame([['currency' => 'GBP', 'amount_minor' => 1200]], $funnel['store_gmv']['totals']);
    }

    public function test_the_funnel_reports_agency_cap_utilisation_against_the_plan_limits(): void
    {
        // agency_starter allows 5 brands and 3 seats (Step GTM-0.1).
        $org = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_starter']);
        Client::factory()->count(5)->for($org, 'organization')->create();
        User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $row = collect(app(GetFunnelMetricsAction::class)()['agency_utilisation'])
            ->firstWhere('plan', 'agency_starter');

        $this->assertNotNull($row);
        $this->assertSame(5, $row['brand_limit']);
        $this->assertSame(3, $row['seat_limit']);
        $this->assertSame(5.0, $row['brands_used']);
        $this->assertSame(100.0, $row['brand_utilisation']);
        $this->assertSame(1, $row['at_brand_cap'], 'An agency at its cap is an upgrade waiting to happen.');
    }

    public function test_the_super_admin_analytics_page_carries_the_funnel(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/analytics/index')
                ->has('funnel.conversion')
                ->has('funnel.time_to_first_post')
                ->has('funnel.bio_pages')
                ->has('funnel.store_gmv')
                ->has('funnel.churn_by_plan')
                ->has('funnel.agency_utilisation'));
    }

    // ---------------------------------------------------------------- GTM-7.2

    public function test_the_competitor_review_is_silent_until_the_quarter_is_up(): void
    {
        $alerts = [];
        $this->app->instance(OpsAlerter::class, new class($alerts) implements OpsAlerter
        {
            /**
             * @param  array<int, string>  $alerts
             */
            public function __construct(public array &$alerts) {}

            public function alert(string $key, string $message, array $context = []): void
            {
                $this->alerts[] = $key;
            }
        });

        config(['competitors.last_checked' => now()->subDays(10)->toDateString()]);
        $this->artisan('competitors:review-due')->assertSuccessful();
        $this->assertSame([], $alerts, 'Fresh figures are not worth an alert.');

        config(['competitors.last_checked' => now()->subDays(120)->toDateString()]);
        $this->artisan('competitors:review-due')->assertSuccessful();
        $this->assertSame(['marketing.competitor_review_due'], $alerts);
    }

    // ---------------------------------------------------------------- GTM-7.3

    public function test_every_comparison_page_renders_with_its_dated_figures(): void
    {
        foreach (array_keys((array) config('competitors.rivals')) as $slug) {
            $this->get(route('compare', $slug))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('marketing/compare')
                    ->where('rival.slug', $slug)
                    ->where('lastChecked', (string) config('competitors.last_checked'))
                    // The rival's own strengths are on the page: a comparison that pretends
                    // otherwise gets quoted back at you.
                    ->has('rival.strengths')
                    ->has('rival.ours')
                    ->has('others'));
        }

        $this->get(route('compare', 'not-a-rival'))->assertNotFound();
    }

    public function test_the_footer_comparison_links_match_the_configured_rivals(): void
    {
        // The shell hard-codes the four links so marketing pages don't carry the competitor
        // config; this is what stops the two drifting apart unnoticed.
        $shell = file_get_contents(resource_path('js/components/marketing/marketing-shell.tsx'));

        foreach (array_keys((array) config('competitors.rivals')) as $slug) {
            $this->assertStringContainsString("'{$slug}'", (string) $shell);
        }
    }

    public function test_the_partner_and_agent_pages_are_public(): void
    {
        $this->get(route('partners'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('marketing/partners')->has('commissionPercent'));

        $this->get(route('agents'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('marketing/agents'));
    }

    public function test_a_referral_code_attributes_a_signup_end_to_end(): void
    {
        $partner = ReferralPartner::create(['code' => 'thatblog', 'name' => 'That Blog', 'commission_percent' => 25]);

        // One visit to any marketing page with the code, then a signup — possibly days later.
        $this->get(route('pricing', ['ref' => 'ThatBlog']))->assertOk()->assertCookie(ReferralPartner::COOKIE);

        $this->withUnencryptedCookie(ReferralPartner::COOKIE, 'thatblog')
            ->post(route('organizations.register.store'), [
                'organization_name' => 'Referred Studio',
                'name' => 'Jo Owner',
                'email' => 'jo@referred.test',
                'password' => 'password-is-long-enough',
                'password_confirmation' => 'password-is-long-enough',
                'default_timezone' => 'Europe/London',
                'default_currency' => 'GBP',
            ]);

        $org = Organization::query()->where('name', 'Referred Studio')->sole();
        $this->assertSame($partner->id, $org->referral_partner_id);
        $this->assertNotNull($org->referred_at);
    }

    public function test_an_unknown_or_ended_code_is_never_stored(): void
    {
        $this->get(route('pricing', ['ref' => 'nosuchcode']))->assertOk()->assertCookieMissing(ReferralPartner::COOKIE);

        ReferralPartner::create([
            'code' => 'retired',
            'name' => 'Retired Blog',
            'commission_percent' => 20,
            'ended_at' => now()->subDay(),
        ]);

        $this->get(route('pricing', ['ref' => 'retired']))->assertOk()->assertCookieMissing(ReferralPartner::COOKIE);
    }

    public function test_first_touch_wins(): void
    {
        ReferralPartner::create(['code' => 'first', 'name' => 'First', 'commission_percent' => 20]);
        ReferralPartner::create(['code' => 'second', 'name' => 'Second', 'commission_percent' => 20]);

        // Whoever introduced them earned it, not whichever link they happened to click last.
        $this->withUnencryptedCookie(ReferralPartner::COOKIE, 'first')
            ->get(route('pricing', ['ref' => 'second']))
            ->assertOk()
            ->assertCookieMissing(ReferralPartner::COOKIE);
    }

    public function test_a_super_admin_adds_a_partner_records_a_payout_and_ends_a_partnership(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('super-admin.referrals.store'), [
            'code' => 'ThatBlog',
            'name' => 'That Blog',
            'email' => 'hello@thatblog.test',
            'commission_percent' => 25,
        ])->assertRedirect();

        $partner = ReferralPartner::query()->sole();
        $this->assertSame('thatblog', $partner->code, 'Codes are normalised, so the unique index means something.');

        // A duplicate is refused rather than creating a second code that cannot be told apart.
        $this->actingAs($admin)->post(route('super-admin.referrals.store'), [
            'code' => 'thatblog',
            'name' => 'Copycat',
            'commission_percent' => 25,
        ])->assertSessionHasErrors('code');

        $this->actingAs($admin)->post(route('super-admin.referrals.payouts.store', $partner), [
            'amount_minor' => 12000,
            'currency' => 'GBP',
            'period_start' => '2026-10-01',
            'period_end' => '2026-12-31',
            'reference' => 'Bank transfer',
        ])->assertRedirect();

        $payout = ReferralPayout::query()->sole();
        $this->assertSame(12000, $payout->amount_minor);
        $this->assertNotNull($payout->paid_at, 'A payout records money already sent.');

        $this->actingAs($admin)->post(route('super-admin.referrals.end', $partner))->assertRedirect();
        $this->assertFalse($partner->refresh()->isActive());

        $this->actingAs($admin)
            ->get(route('super-admin.referrals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/referrals/index')
                ->has('partners', 1)
                ->where('partners.0.code', 'thatblog')
                ->where('partners.0.active', false));
    }

    public function test_ending_a_partnership_keeps_the_customers_they_already_sent(): void
    {
        $partner = ReferralPartner::create(['code' => 'thatblog', 'name' => 'That Blog', 'commission_percent' => 20]);
        $org = Organization::factory()->create([
            'referral_partner_id' => $partner->id,
            'referred_at' => now(),
        ]);

        $this->actingAs($this->superAdmin())->post(route('super-admin.referrals.end', $partner))->assertRedirect();

        $this->assertSame($partner->id, $org->refresh()->referral_partner_id);
    }

    public function test_only_a_super_admin_reaches_the_referral_console(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('super-admin.referrals.index'))->assertForbidden();
        $this->actingAs($owner)->post(route('super-admin.referrals.store'), [
            'code' => 'sneaky',
            'name' => 'Sneaky',
            'commission_percent' => 90,
        ])->assertForbidden();

        $this->assertSame(0, ReferralPartner::query()->count());
    }
}
