<?php

namespace Tests\Feature\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\AiTrend;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
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

    public function test_each_tab_renders_its_own_paginated_data(): void
    {
        Post::factory()->for($this->client)->count(30)->create(['org_id' => $this->org->id]);
        Asset::factory()->for($this->client)->count(26)->create(['org_id' => $this->org->id]);
        Campaign::factory()->for($this->client)->count(2)->create(['org_id' => $this->org->id]);
        Invoice::factory()->for($this->client)->count(2)->create(['org_id' => $this->org->id]);
        ClientInvitation::factory()->for($this->client)->create();

        $tabs = [
            ['clients.posts.index', 'clients/tabs/posts', 'posts', 25, 30],
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
            ->assertInertia(fn ($page) => $page->has('posts.data', 5)->has('targetAccounts'));
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
        $posts->assertInertia(fn ($page) => $page->has('posts.data', 25)->where('posts.total', 2000));
        $this->assertLessThan(100_000, strlen((string) json_encode($posts->viewData('page')['props'])));
    }
}
