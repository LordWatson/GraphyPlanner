<?php

namespace Tests\Feature\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Services\ClientProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which clients are worth keeping — the question every agency owner asks and most social tools
 * cannot answer.
 *
 * Effort is inferred rather than timed, so the tests pin the two things that make an estimate
 * trustworthy: that it is built from work the app actually watched happen, and that it refuses to
 * print a margin it cannot compute rather than inventing a flattering one.
 */
class ClientProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            // £50/hour.
            'blended_hourly_rate_minor' => 5000,
        ]);
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function paidInvoice(float $amount): Invoice
    {
        return Invoice::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => InvoiceStatus::Paid,
            'amount' => $amount,
            'paid_at' => now()->subDays(3),
        ]);
    }

    private function postsWithRevisions(int $posts, int $revisionsEach): void
    {
        for ($i = 0; $i < $posts; $i++) {
            $post = Post::factory()->for($this->client)->create([
                'org_id' => $this->org->id,
                'status' => PostStatus::Approved,
            ]);

            for ($r = 0; $r < $revisionsEach; $r++) {
                PostActivityLog::factory()->create([
                    'post_id' => $post->id,
                    'to_status' => PostStatus::ChangesRequested,
                ]);
            }
        }
    }

    public function test_revenue_comes_from_paid_invoices_only(): void
    {
        $this->paidInvoice(1000);

        Invoice::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => InvoiceStatus::Sent,
            'amount' => 9999,
            'paid_at' => null,
        ]);

        // An invoice that was sent and never settled is not revenue — counting it would flatter
        // exactly the client an owner most needs to look at.
        $this->assertSame(100_000, app(ClientProfitabilityService::class)->forClient($this->client)['revenue_minor']);
    }

    public function test_effort_is_inferred_from_work_the_app_watched(): void
    {
        $this->postsWithRevisions(posts: 4, revisionsEach: 2);

        $result = app(ClientProfitabilityService::class)->forClient($this->client);

        $this->assertSame(4, $result['effort']['posts']);
        $this->assertSame(8, $result['effort']['revision_rounds']);
        $this->assertTrue($result['inferred']);

        // 4 posts × 45min + 8 rounds × 30min = 420min = 7h, with the shipped weights.
        $this->assertSame(7.0, $result['hours']);
    }

    public function test_the_margin_is_revenue_minus_hours_at_the_blended_rate(): void
    {
        $this->paidInvoice(1000);
        $this->postsWithRevisions(posts: 4, revisionsEach: 2);

        $result = app(ClientProfitabilityService::class)->forClient($this->client);

        // 7h × £50 = £350 against £1,000 received.
        $this->assertSame(35_000, $result['cost_minor']);
        $this->assertSame(65_000, $result['margin_minor']);
        $this->assertSame(65.0, $result['margin_pct']);
    }

    /**
     * Without a rate there is no margin, and saying so is the only useful answer. Reporting 100%
     * would be arithmetically true and completely misleading.
     */
    public function test_no_blended_rate_means_no_margin_rather_than_a_flattering_one(): void
    {
        $this->org->update(['blended_hourly_rate_minor' => null]);
        config(['profitability.default_hourly_rate_minor' => 0]);
        $this->paidInvoice(1000);
        $this->postsWithRevisions(posts: 2, revisionsEach: 0);

        $result = app(ClientProfitabilityService::class)->forClient($this->client);

        $this->assertFalse($result['rate_configured']);
        $this->assertNull($result['cost_minor']);
        $this->assertNull($result['margin_minor']);
        $this->assertNull($result['margin_pct']);
        // The effort is still reported — it is the part that does not need a rate.
        $this->assertGreaterThan(0, $result['hours']);
    }

    public function test_a_tracked_clients_own_hours_beat_the_inferred_ones(): void
    {
        $this->client->update(['estimated_hours_per_month' => 10]);
        $this->postsWithRevisions(posts: 40, revisionsEach: 5);

        $result = app(ClientProfitabilityService::class)->forClient($this->client, months: 3);

        // The escape hatch for agencies that do track time: 10h × 3 months, not the inference.
        $this->assertSame(30.0, $result['hours']);
        $this->assertFalse($result['inferred']);
    }

    /**
     * The pattern that quietly destroys an agency's margin, and which no per-post view shows.
     */
    public function test_climbing_revision_rounds_are_flagged(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
        ]);

        // Two rounds three-to-six months ago, six in the recent three.
        foreach ([5, 5] as $monthsAgo) {
            PostActivityLog::factory()->create(['post_id' => $post->id, 'to_status' => PostStatus::ChangesRequested])
                ->forceFill(['created_at' => now()->subMonths($monthsAgo)])->save();
        }

        for ($i = 0; $i < 6; $i++) {
            PostActivityLog::factory()->create(['post_id' => $post->id, 'to_status' => PostStatus::ChangesRequested])
                ->forceFill(['created_at' => now()->subDays(5)])->save();
        }

        $result = app(ClientProfitabilityService::class)->forClient($this->client);

        $this->assertSame(6, $result['revisions_recent']);
        $this->assertSame(2, $result['revisions_previous']);
        $this->assertTrue($result['revisions_climbing']);
    }

    public function test_steady_revisions_are_not_flagged(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Approved,
        ]);

        PostActivityLog::factory()->create(['post_id' => $post->id, 'to_status' => PostStatus::ChangesRequested])
            ->forceFill(['created_at' => now()->subMonths(5)])->save();
        PostActivityLog::factory()->create(['post_id' => $post->id, 'to_status' => PostStatus::ChangesRequested])
            ->forceFill(['created_at' => now()->subDays(5)])->save();

        // One each way is not a trend.
        $this->assertFalse(app(ClientProfitabilityService::class)->forClient($this->client)['revisions_climbing']);
    }

    public function test_the_roster_is_ordered_worst_margin_first(): void
    {
        $this->paidInvoice(2000);
        $this->postsWithRevisions(posts: 1, revisionsEach: 0);

        $thin = Client::factory()->for($this->org, 'organization')->create(['name' => 'Thin']);
        Invoice::factory()->for($thin)->create([
            'org_id' => $this->org->id,
            'status' => InvoiceStatus::Paid,
            'amount' => 100,
            'paid_at' => now()->subDay(),
        ]);
        for ($i = 0; $i < 10; $i++) {
            $post = Post::factory()->for($thin)->create(['org_id' => $this->org->id, 'status' => PostStatus::Approved]);
            PostActivityLog::factory()->count(3)->create(['post_id' => $post->id, 'to_status' => PostStatus::ChangesRequested]);
        }

        $rows = app(ClientProfitabilityService::class)->forOrganization($this->org);

        // Worst first, because that is the order an owner wants to read it in.
        $this->assertSame('Thin', $rows[0]['client_name']);
        $this->assertLessThan($rows[1]['margin_minor'], $rows[0]['margin_minor']);
    }

    public function test_another_organizations_clients_are_never_included(): void
    {
        $otherOrg = Organization::factory()->create();
        Client::factory()->for($otherOrg, 'organization')->create(['name' => 'Theirs']);

        $names = array_column(app(ClientProfitabilityService::class)->forOrganization($this->org), 'client_name');

        $this->assertNotContains('Theirs', $names);
    }

    // --- the page ----------------------------------------------------------------------------

    public function test_an_owner_sees_the_roster(): void
    {
        $this->paidInvoice(1000);
        $this->postsWithRevisions(posts: 2, revisionsEach: 1);

        $this->actingAs($this->owner)->get(route('client-profitability'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/profitability')
                ->where('rateConfigured', true)
                ->has('clients', 1)
                ->where('clients.0.client_name', 'Acme'));
    }

    public function test_the_page_says_so_when_no_rate_is_configured(): void
    {
        $this->org->update(['blended_hourly_rate_minor' => null]);
        config(['profitability.default_hourly_rate_minor' => 0]);

        $this->actingAs($this->owner)->get(route('client-profitability'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rateConfigured', false));
    }

    /**
     * A margin is strictly more sensitive than the retainer it is computed from, so it uses the
     * same gate the roster already uses to hide retainer figures from non-billing roles.
     */
    public function test_a_role_without_billing_access_cannot_see_it(): void
    {
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->get(route('client-profitability'))->assertForbidden();
    }

    public function test_an_owner_can_set_the_blended_rate_in_settings(): void
    {
        $this->confirmPassword()->actingAs($this->owner)->put(route('organization.update'), [
            'default_timezone' => 'UTC',
            'default_currency' => 'GBP',
            'blended_hourly_rate_minor' => 7500,
        ])->assertRedirect(route('organization.edit'));

        $this->assertSame(7500, $this->org->fresh()->blended_hourly_rate_minor);
    }
}
