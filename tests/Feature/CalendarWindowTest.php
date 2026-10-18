<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Calendar\CalendarWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step I-2.3 — the calendar only loads the visible window.
 */
class CalendarWindowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $this->org = Organization::factory()->create(['default_timezone' => 'UTC']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'timezone' => 'UTC',
        ]);
    }

    public function test_the_default_window_is_the_current_month_grid(): void
    {
        $window = CalendarWindow::monthGrid(CarbonImmutable::parse('2026-10-15'));

        // 1 October 2026 is a Thursday, so the Sunday-first grid starts on 27 September.
        $this->assertSame(['from' => '2026-09-27', 'to' => '2026-11-07'], $window->toArray());

        $this->actingAs($this->owner)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page->where('window', ['from' => '2026-09-27', 'to' => '2026-11-07']));
    }

    public function test_requested_spans_are_capped(): void
    {
        $window = CalendarWindow::resolve('2020-01-01', '2030-01-01', 'UTC');

        $this->assertSame('2020-03-03', $window->to->toDateString());
    }

    public function test_the_payload_is_independent_of_history_outside_the_window(): void
    {
        $inWindow = $this->postOn('2026-10-10');

        $before = $this->actingAs($this->owner)->get(route('calendar'));
        $before->assertInertia(fn ($page) => $page->has('occurrences', 1)->where('occurrences.0.post_id', $inWindow->id));

        $this->bulkHistory(5000, '2025-03-01');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $after = $this->actingAs($this->owner)->get(route('calendar'));
        DB::disableQueryLog();

        $after->assertInertia(fn ($page) => $page->has('occurrences', 1));
        $this->assertSame(
            json_encode($before->viewData('page')['props']['occurrences']),
            json_encode($after->viewData('page')['props']['occurrences']),
        );
        $this->assertLessThan(20_000, strlen((string) json_encode($after->viewData('page')['props'])));
    }

    public function test_navigating_to_another_window_keeps_filters(): void
    {
        $other = Client::factory()->for($this->org, 'organization')->create();
        $december = $this->postOn('2026-12-05');
        $otherDecember = $this->postOn('2026-12-06', $other);

        $this->actingAs($this->owner)
            ->get(route('calendar', ['from' => '2026-11-29', 'to' => '2027-01-09', 'client_id' => $this->client->id]))
            ->assertInertia(fn ($page) => $page
                ->where('window', ['from' => '2026-11-29', 'to' => '2027-01-09'])
                ->where('filters.client_id', $this->client->id)
                ->has('occurrences', 1)
                ->where('occurrences.0.post_id', $december->id)
            );

        $this->assertNotNull($otherDecember);
    }

    public function test_reschedulable_ids_follow_the_policy_as_a_set(): void
    {
        $draft = $this->postOn('2026-10-10');
        $published = $this->postOn('2026-10-11', status: PostStatus::Published);
        $partlyOut = $this->postOn('2026-10-12');
        $partlyOut->targets()->update(['status' => PostTargetStatus::Published]);

        $this->actingAs($this->owner)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page->where('reschedulablePostIds', [$draft->id]));

        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page->where('reschedulablePostIds', []));

        $this->assertNotNull($published);
    }

    public function test_undated_posts_are_counted_and_listed_on_demand(): void
    {
        $undated = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $noDate = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $noDate->targets()->create(['social_account_id' => $this->account->id]);
        $this->postOn('2026-10-10');

        $this->actingAs($this->owner)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page
                ->where('unscheduledCount', 2)
                ->missing('unscheduled')
                ->reloadOnly('unscheduled', fn ($reload) => $reload
                    ->where('unscheduled.total', 2)
                    ->where('unscheduled.data.0.id', $noDate->id)
                    ->where('unscheduled.data.1.id', $undated->id)
                )
            );
    }

    private function postOn(string $date, ?Client $client = null, PostStatus $status = PostStatus::Draft): Post
    {
        $client ??= $this->client;
        $account = $client->is($this->client)
            ? $this->account
            : SocialAccount::factory()->for($client)->create(['org_id' => $this->org->id, 'timezone' => 'UTC']);

        $post = Post::factory()->for($client)->create(['org_id' => $this->org->id, 'status' => $status]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => $date,
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => $date.'T09:00:00+00:00',
        ]);

        return $post;
    }

    private function bulkHistory(int $count, string $date): void
    {
        $now = now();

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            $firstId = (int) DB::table('posts')->max('id') + 1;

            DB::table('posts')->insert(array_map(fn () => [
                'org_id' => $this->org->id,
                'client_id' => $this->client->id,
                'status' => PostStatus::Published->value,
                'approval_mode' => 'client_required',
                'master_caption' => str_repeat('History ', 10),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));

            DB::table('post_targets')->insert(array_map(fn (int $offset) => [
                'post_id' => $firstId + $offset,
                'social_account_id' => $this->account->id,
                'scheduled_local_date' => $date,
                'scheduled_local_time' => '09:00',
                'scheduled_at_utc' => $date.' 09:00:00',
                'status' => PostTargetStatus::Published->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($chunk)));
        }
    }
}
