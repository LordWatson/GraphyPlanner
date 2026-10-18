<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step I-2.7 — summaries are cached per (org, filters, viewer) and dropped when a write they
 * depend on happens.
 */
class AnalyticsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_summary_is_served_from_cache_until_a_relevant_write(): void
    {
        $org = Organization::factory()->create()->refresh();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $summary = fn () => app(GetOrganizationAnalyticsSummaryAction::class)(
            $org,
            OrganizationAnalyticsFilters::fromArray([], $org),
            $owner,
        );

        $first = $summary();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $summary();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame(0, $queries, 'A warm summary should not touch the database.');

        // A write that bypasses model events isn't seen until the TTL…
        DB::table('posts')->where('id', $post->id)->update(['status' => PostStatus::Scheduled->value]);
        $this->assertSame($first['posts']['scheduled'], $summary()['posts']['scheduled']);

        // …but a normal status change drops the org's cached summaries straight away.
        $post->refresh()->update(['status' => PostStatus::Approved]);
        $this->assertSame(0, $summary()['posts']['scheduled']);
    }

    public function test_cached_summaries_are_per_viewer(): void
    {
        $org = Organization::factory()->create()->refresh();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        $summary = fn (User $viewer) => app(GetOrganizationAnalyticsSummaryAction::class)(
            $org,
            OrganizationAnalyticsFilters::fromArray([], $org),
            $viewer,
        );

        $this->assertNotNull($summary($owner)['revenue']);
        $this->assertNull($summary($designer)['revenue']);
    }
}
