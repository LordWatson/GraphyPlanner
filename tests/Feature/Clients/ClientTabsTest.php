<?php

namespace Tests\Feature\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiTrend;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step I-2.4 — every client tab is its own route with its own (paginated) data, sharing the
 * header from `BuildClientPageHeaderAction`.
 */
class ClientTabsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_the_overview_is_the_accounts_tab_with_header_counts(): void
    {
        SocialAccount::factory()->for($this->client)->count(2)->create(['org_id' => $this->org->id]);
        Post::factory()->for($this->client)->count(3)->create(['org_id' => $this->org->id]);
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'amount' => 120, 'currency' => 'USD']);
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'amount' => 30, 'currency' => 'USD']);
        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'amount' => 50, 'currency' => 'EUR']);

        $this->actingAs($this->owner)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/tabs/accounts')
                ->where('tab', 'accounts')
                ->where('client.id', $this->client->id)
                ->has('socialAccounts', 2)
                ->has('timezones')
                ->where('stats.socialAccounts', 2)
                ->where('stats.posts', 3)
                ->where('stats.billing.invoices', 3)
                ->where('stats.billing.outstanding', [
                    ['currency' => 'EUR', 'total' => 50],
                    ['currency' => 'USD', 'total' => 150],
                ])
                ->missing('posts')
                ->missing('assets')
                ->missing('invoices'));
    }

    public function test_a_fresh_client_exposes_an_incomplete_setup_checklist(): void
    {
        // MOVE 5 (ux-overhaul): a brand-new client has every setup step outstanding.
        $this->actingAs($this->owner)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('setup.brandBrain', false)
                ->where('setup.accounts', false)
                ->where('setup.portal', false)
                ->where('setup.content', false)
                ->where('setup.complete', false));
    }

    public function test_setup_checklist_steps_tick_off_and_complete(): void
    {
        // Accounts, portal invite and first post done, but the Brand Brain is still empty.
        SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        ClientInvitation::factory()->for($this->client)->create();
        Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('setup.accounts', true)
                ->where('setup.portal', true)
                ->where('setup.content', true)
                ->where('setup.brandBrain', false)
                ->where('setup.complete', false));

        // Filling any core Brand Brain field completes the last step.
        $this->client->brandBrain()->create([
            'voice' => ['tone' => 'Warm', 'personality' => null, 'do_nots' => []],
        ]);

        $this->actingAs($this->owner)
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('setup.brandBrain', true)
                ->where('setup.complete', true));
    }

    public function test_each_tab_renders_its_own_paginated_data(): void
    {
        Post::factory()->for($this->client)->count(30)->create(['org_id' => $this->org->id]);
        Asset::factory()->for($this->client)->count(26)->create(['org_id' => $this->org->id]);
        Campaign::factory()->for($this->client)->count(2)->create(['org_id' => $this->org->id]);
        Invoice::factory()->for($this->client)->count(2)->create(['org_id' => $this->org->id]);
        ClientInvitation::factory()->for($this->client)->create();

        $tabs = [
            ['clients.posts.index', 'clients/tabs/posts', 'posts', 8, 30],
            ['clients.campaigns.index', 'clients/tabs/campaigns', 'campaigns', 2, 2],
            ['clients.billing', 'clients/tabs/billing', 'invoices', 2, 2],
            ['clients.portal-access', 'clients/tabs/portal-access', 'invitations', 1, 1],
        ];

        foreach ($tabs as [$route, $component, $prop, $onPage, $total]) {
            $this->actingAs($this->owner)
                ->get(route($route, $this->client))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component($component)
                    ->has('client')
                    ->has('stats')
                    ->has("{$prop}.data", $onPage)
                    ->where("{$prop}.total", $total));
        }

        $this->actingAs($this->owner)
            ->get(route('clients.posts.index', [$this->client, 'page' => 2]))
            ->assertInertia(fn ($page) => $page->has('posts.data', 8)->where('posts.current_page', 2)->has('targetAccounts'));
    }

    public function test_the_posts_tab_filters_by_search_status_campaign_and_channel(): void
    {
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id, 'name' => 'Summer']);
        $instagram = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::Instagram]);
        $tiktok = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok]);

        $launch = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'master_caption' => 'Big LAUNCH day', 'status' => PostStatus::Draft, 'campaign_id' => $campaign->id]);
        $scheduled = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'master_caption' => 'Behind the scenes', 'status' => PostStatus::Scheduled]);
        $percent = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'master_caption' => '50% off', 'status' => PostStatus::Draft]);
        PostTarget::factory()->create(['post_id' => $launch->id, 'social_account_id' => $instagram->id]);
        PostTarget::factory()->create(['post_id' => $scheduled->id, 'social_account_id' => $tiktok->id]);

        $ids = fn (array $query) => $this->listedIds('clients.posts.index', 'posts', $query);

        $this->assertSame([$launch->id], $ids(['search' => 'launch']));
        // `%` is matched literally, not as a wildcard.
        $this->assertSame([$percent->id], $ids(['search' => '%']));
        $this->assertSame([$scheduled->id], $ids(['status' => 'scheduled']));
        $this->assertSame([$launch->id], $ids(['campaign' => $campaign->id]));
        $this->assertSame([$scheduled->id], $ids(['platform' => 'tiktok']));
        $this->assertSame([$launch->id, $percent->id], $ids(['status' => 'draft']));
        $this->assertSame([], $ids(['status' => 'draft', 'platform' => 'tiktok']));

        $this->actingAs($this->owner)
            ->get(route('clients.posts.index', [$this->client, 'status' => 'scheduled', 'search' => ' scenes ']))
            ->assertInertia(fn ($page) => $page
                ->where('filters', ['search' => 'scenes', 'status' => 'scheduled', 'campaign' => null, 'platform' => null])
                ->where('filterOptions.campaigns', [['value' => (string) $campaign->id, 'label' => 'Summer']])
                ->has('filterOptions.statuses', count(PostStatus::cases())));
    }

    public function test_the_posts_tab_hides_archived_posts_unless_explicitly_filtered(): void
    {
        $draft = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);
        $archived = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Archived]);

        $ids = fn (array $query) => $this->listedIds('clients.posts.index', 'posts', $query);

        // Default list excludes archived posts.
        $this->assertSame([$draft->id], $ids([]));
        // Filtering by another status is unaffected and still never shows archived.
        $this->assertSame([$draft->id], $ids(['status' => 'draft']));
        // The archived posts are still reachable by explicitly selecting the Archived status.
        $this->assertSame([$archived->id], $ids(['status' => 'archived']));
    }

    public function test_the_posts_tab_never_lists_another_clients_posts(): void
    {
        $other = Client::factory()->for($this->org, 'organization')->create();
        $otherCampaign = Campaign::factory()->for($other)->create(['org_id' => $this->org->id]);
        Post::factory()->for($other)->create(['org_id' => $this->org->id, 'campaign_id' => $otherCampaign->id]);

        $this->actingAs($this->owner)
            ->get(route('clients.posts.index', [$this->client, 'campaign' => $otherCampaign->id]))
            ->assertInertia(fn ($page) => $page->has('posts.data', 0)->has('filterOptions.campaigns', 0));
    }

    public function test_the_posts_tab_rejects_unknown_filter_values(): void
    {
        $this->actingAs($this->owner)
            ->get(route('clients.posts.index', [$this->client, 'status' => 'bogus', 'platform' => 'myspace']))
            ->assertSessionHasErrors(['status', 'platform']);
    }

    public function test_the_billing_tab_pages_by_eight_and_filters_by_status_and_search(): void
    {
        Invoice::factory()->for($this->client)->count(9)->create(['org_id' => $this->org->id]);
        $overdue = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->subWeek(), 'invoice_number' => 'INV-OVERDUE']);
        $sent = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'due_date' => now()->addWeek(), 'description' => 'Retainer for October']);
        $paid = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        // Another client's invoice must never leak into this tab.
        Invoice::factory()->for(Client::factory()->for($this->org, 'organization'))->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Paid]);

        $this->actingAs($this->owner)
            ->get(route('clients.billing', $this->client))
            ->assertInertia(fn ($page) => $page
                ->has('invoices.data', 8)
                ->where('invoices.total', 12)
                ->where('filters', ['search' => null, 'status' => null]));

        $ids = fn (array $query) => $this->listedIds('clients.billing', 'invoices', $query);

        $this->assertSame([$overdue->id], $ids(['status' => 'overdue']));
        $this->assertSame([$overdue->id, $sent->id], $ids(['status' => 'outstanding']));
        $this->assertSame([$paid->id], $ids(['status' => 'paid']));
        $this->assertSame([$overdue->id], $ids(['search' => 'inv-overdue']));
        $this->assertSame([$sent->id], $ids(['search' => 'october', 'status' => 'sent']));

        $this->actingAs($this->owner)
            ->get(route('clients.billing', [$this->client, 'status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    /**
     * The sorted ids of the rows a tab lists for the given query string.
     *
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(string $route, string $prop, array $query): array
    {
        $rows = $this->actingAs($this->owner)
            ->get(route($route, [$this->client, ...$query]))
            ->assertOk()
            ->viewData('page')['props'][$prop]['data'];

        $ids = array_column($rows, 'id');
        sort($ids);

        return $ids;
    }

    /**
     * The Assets tab loads no asset rows — only the header count. The library is browsed through
     * the paged `clients.assets.index` dialog instead.
     */
    public function test_the_assets_tab_only_carries_the_asset_count(): void
    {
        Asset::factory()->for($this->client)->count(26)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner)
            ->get(route('clients.media', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/tabs/media')
                ->where('tab', 'media')
                ->where('stats.assets', 26)
                ->where('can.createAsset', true)
                ->missing('assets'));
    }

    public function test_the_ai_tab_defers_trends_and_ideas_and_is_gated(): void
    {
        config(['ai.enabled' => true]);
        AiTrend::query()->create(['org_id' => $this->org->id, 'client_id' => $this->client->id, 'title' => 'Short video', 'summary' => 'Up', 'platforms' => ['tiktok'], 'confidence' => 80]);

        $this->actingAs($this->owner)
            ->get(route('clients.ai.index', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/tabs/ai')
                ->missing('trends')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->has('trends', 1)
                    ->where('trends.0.title', 'Short video')
                    ->has('ideas', 0)));

        $this->actingAs($this->owner)->get(route('clients.briefs', $this->client))->assertOk();

        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
        $this->actingAs($designer)->get(route('clients.ai.index', $this->client))->assertForbidden();
        $this->actingAs($designer)->get(route('clients.briefs', $this->client))->assertForbidden();

        config(['ai.enabled' => false]);
        $this->actingAs($this->owner)->get(route('clients.ai.index', $this->client))->assertForbidden();
    }

    public function test_tabs_refuse_viewers_without_the_matching_ability(): void
    {
        $designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->get(route('clients.billing', $this->client))->assertForbidden();
        $this->actingAs($designer)->get(route('clients.portal-access', $this->client))->assertForbidden();
        $this->actingAs($designer)->get(route('clients.posts.index', $this->client))->assertOk();

        $stranger = User::factory()->role(Role::Owner)->create();

        foreach (['clients.show', 'clients.posts.index', 'clients.media', 'clients.campaigns.index', 'clients.billing', 'clients.portal-access'] as $route) {
            $status = $this->actingAs($stranger)->get(route($route, $this->client))->status();
            $this->assertContains($status, [403, 404], "{$route} leaked across orgs");
        }
    }

    public function test_the_overview_stays_small_for_a_client_with_two_thousand_posts(): void
    {
        $now = now();

        foreach (array_chunk(range(1, 2000), 500) as $chunk) {
            DB::table('posts')->insert(array_map(fn () => [
                'org_id' => $this->org->id,
                'client_id' => $this->client->id,
                'status' => 'draft',
                'approval_mode' => 'client_required',
                'master_caption' => str_repeat('A caption long enough to matter. ', 6),
                'hashtags' => json_encode(['launch', 'summer']),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $overview = $this->actingAs($this->owner)->get(route('clients.show', $this->client));
        $overview->assertInertia(fn ($page) => $page->where('stats.posts', 2000));
        $this->assertLessThan(100_000, strlen((string) json_encode($overview->viewData('page')['props'])));

        $posts = $this->actingAs($this->owner)->get(route('clients.posts.index', $this->client));
        $posts->assertInertia(fn ($page) => $page->has('posts.data', 8)->where('posts.total', 2000));
        $this->assertLessThan(100_000, strlen((string) json_encode($posts->viewData('page')['props'])));
    }
}
