<?php

namespace Tests\Feature\Stories;

use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Actions\Posts\GetMyWorkAction;
use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\ProcessStoryHandoffsAction;
use App\Enums\PostStatus;
use App\Enums\PublishFailureReason;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Steps S-5.1 / S-5.2 — the assisted lane shows up on every list surface: the calendar, the content
 * hub, the client posts tab, My work, the portal calendar, and the "Stories to post" groups on
 * Needs attention and My work.
 */
class StorySurfacesTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private const NEEDS = ['state' => 'needs_hand_posting', 'label' => 'Needs posting by hand'];

    private const READY = ['state' => 'ready_to_post', 'label' => 'Ready to post'];

    private const POSTED = ['state' => 'posted_by_hand', 'label' => 'Posted by hand'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 09:00:00');
        $this->setUpStoryHandoffs();
        $this->org->update(['default_timezone' => 'UTC']);
        Notification::fake();

        $this->owner = $this->member(Role::Owner);
    }

    /**
     * One post in each state the lane can be in, all on 20 November: a scheduled assisted Story, one
     * whose handoff is open and due, one a person confirmed, and a plain auto-publishing channel.
     *
     * @return array{scheduled: Post, ready: Post, posted: Post, plain: Post}
     */
    private function oneOfEach(?User $assignee = null): array
    {
        $attributes = ['assignee_id' => ($assignee ?? $this->owner)->id];

        $scheduled = $this->storyPost(postAttributes: $attributes);
        $ready = $this->storyPost(postAttributes: $attributes);
        $posted = $this->storyPost(postAttributes: $attributes);
        $plain = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Scheduled, ...$attributes]);
        $this->addFeedChannel($plain);

        $this->refusingAdapter();
        $this->travelTo('2026-11-20 10:01:00');
        $this->startPublishing($ready);
        $this->startPublishing($posted);
        app(CompleteStoryHandoffAction::class)($this->openHandoff($posted), $this->owner, StoryHandoff::VIA_STAFF);

        return ['scheduled' => $scheduled, 'ready' => $ready->refresh(), 'posted' => $posted->refresh(), 'plain' => $plain];
    }

    /**
     * A `publishing` Story whose handoff is open and due, with `$poster` on the hook for it.
     */
    private function dueStory(User $poster): Post
    {
        $post = $this->storyPost(postAttributes: ['assignee_id' => $poster->id]);
        $this->refusingAdapter();
        $this->travelTo('2026-11-20 10:01:00');

        return $this->startPublishing($post);
    }

    /**
     * @return array<int, mixed> the page's rows keyed by post id
     */
    private function byPost(TestResponse $response, string $prop, string $key = 'post_id'): array
    {
        return collect(data_get($response->viewData('page')['props'], $prop))->keyBy($key)->all();
    }

    public function test_calendar_occurrences_carry_the_channel_state(): void
    {
        $posts = $this->oneOfEach();

        $rows = $this->byPost($this->actingAs($this->owner)->get(route('calendar'))->assertOk(), 'occurrences');

        $this->assertSame(self::NEEDS, $rows[$posts['scheduled']->id]['handoff']);
        $this->assertSame(self::READY, $rows[$posts['ready']->id]['handoff']);
        $this->assertSame(self::POSTED, $rows[$posts['posted']->id]['handoff']);
        $this->assertNull($rows[$posts['plain']->id]['handoff']);
    }

    public function test_the_calendar_query_count_does_not_grow_with_assisted_posts(): void
    {
        $this->oneOfEach();

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('calendar'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queries(); // warm the acting user's relations
        $few = $queries();

        foreach (range(1, 6) as $i) {
            $this->startPublishing($this->storyPost(utc: '2026-11-20 10:00:00'));
            $this->storyPost(utc: '2026-11-21 10:00:00');
        }

        $this->assertSame($few, $queries());
    }

    public function test_the_portal_calendar_never_says_ready_to_post(): void
    {
        $posts = $this->oneOfEach();
        $contact = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);

        $response = $this->actingAs($contact)->get(route('portal.calendar'))->assertOk();
        $rows = $this->byPost($response, 'occurrences');

        $this->assertSame(self::NEEDS, $rows[$posts['scheduled']->id]['handoff']);
        $this->assertSame(self::NEEDS, $rows[$posts['ready']->id]['handoff']);
        $this->assertSame(self::POSTED, $rows[$posts['posted']->id]['handoff']);
        $this->assertNull($rows[$posts['plain']->id]['handoff']);
        $this->assertStringNotContainsString('ready_to_post', (string) json_encode($response->viewData('page')['props']));
    }

    public function test_content_rows_and_board_cards_carry_the_posts_most_urgent_state(): void
    {
        $posts = $this->oneOfEach();
        // A second, still-scheduled Story on the ready post: the row reads the more urgent one.
        $this->addStoryChannel($posts['ready'], '2026-11-22 10:00:00');

        $rows = $this->byPost($this->actingAs($this->owner)->get(route('content.index'))->assertOk(), 'posts.data', 'id');

        $this->assertSame(self::NEEDS, $rows[$posts['scheduled']->id]['handoff']);
        $this->assertSame(self::READY, $rows[$posts['ready']->id]['handoff']);
        $this->assertSame(self::POSTED, $rows[$posts['posted']->id]['handoff']);
        $this->assertNull($rows[$posts['plain']->id]['handoff']);

        $board = $this->actingAs($this->owner)->get(route('content.index', ['view' => 'board']))->assertOk();
        $cards = collect(data_get($board->viewData('page')['props'], 'board.columns'))->flatMap(fn (array $column) => $column['posts'])->keyBy('id');

        $this->assertSame(self::READY, $cards[$posts['ready']->id]['handoff']);
        $this->assertSame(self::POSTED, $cards[$posts['posted']->id]['handoff']);
        $this->assertNull($cards[$posts['plain']->id]['handoff']);
    }

    public function test_the_client_posts_tab_carries_the_posts_most_urgent_state(): void
    {
        $posts = $this->oneOfEach();

        $rows = $this->byPost($this->actingAs($this->owner)->get(route('clients.posts.index', $this->client))->assertOk(), 'posts.data', 'id');

        $this->assertSame(self::NEEDS, $rows[$posts['scheduled']->id]['handoff']);
        $this->assertSame(self::READY, $rows[$posts['ready']->id]['handoff']);
        $this->assertSame(self::POSTED, $rows[$posts['posted']->id]['handoff']);
        $this->assertNull($rows[$posts['plain']->id]['handoff']);
    }

    public function test_my_work_rows_carry_the_state_and_list_the_viewers_ready_and_upcoming_stories(): void
    {
        $designer = $this->member(Role::Designer);
        $posts = $this->oneOfEach($designer);
        $later = $this->storyPost(utc: '2026-11-20 16:30:00', postAttributes: ['assignee_id' => $designer->id]);
        $someoneElses = $this->storyPost(utc: '2026-11-20 17:00:00', postAttributes: ['assignee_id' => $this->owner->id]);
        $nextWeek = $this->storyPost(utc: '2026-11-27 10:00:00', postAttributes: ['assignee_id' => $designer->id]);

        $buckets = app(GetMyWorkAction::class)($designer);

        $assigned = collect($buckets['assigned'])->keyBy('id');
        $this->assertSame(self::NEEDS, $assigned[$posts['scheduled']->id]['handoff']);
        $this->assertNull($assigned[$posts['plain']->id]['handoff']);

        $stories = collect($buckets['stories'])->keyBy('post_id');
        $this->assertSame('ready', $stories[$posts['ready']->id]['state']);
        $this->assertSame('upcoming', $stories[$later->id]['state']);
        $this->assertSame(['date' => '2026-11-20', 'time' => '16:30', 'timezone' => 'UTC'], $stories[$later->id]['due']);
        $this->assertArrayNotHasKey($someoneElses->id, $stories);
        $this->assertArrayNotHasKey($nextWeek->id, $stories);
        $this->assertArrayNotHasKey($posts['posted']->id, $stories);

        $this->actingAs($designer)->get(route('my-work.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('buckets.stories', $stories->count()));
    }

    public function test_needs_attention_lists_a_due_story_for_its_poster_and_for_an_owner_only(): void
    {
        $poster = $this->member(Role::Designer);
        $otherDesigner = $this->member(Role::Designer);
        $viewer = $this->member(Role::Viewer);
        $otherOrg = Organization::factory()->create();
        Client::factory()->for($otherOrg, 'organization')->create();
        $outsider = $this->member(Role::Owner, $otherOrg);

        $post = $this->dueStory($poster);
        $target = $this->storyTarget($post);

        $stories = fn (User $user): array => app(GetNeedsAttentionItemsAction::class)($user)['storiesToPost'];

        foreach ([$poster, $this->owner] as $user) {
            $bucket = $stories($user);

            $this->assertSame(1, $bucket['total']);
            $this->assertSame($post->id, $bucket['items'][0]['post_id']);
            $this->assertSame('ready', $bucket['items'][0]['state']);
            $this->assertSame('Acme Coffee', $bucket['items'][0]['client_name']);
            $this->assertSame($poster->name, $bucket['items'][0]['poster_name']);
            $this->assertSame(route('posts.story-handoff.open', ['post' => $post->id, 'target' => $target->id]), $bucket['items'][0]['url']);
        }

        foreach ([$otherDesigner, $viewer, $outsider] as $user) {
            $this->assertSame(['items' => [], 'total' => 0], $stories($user));
        }
    }

    public function test_needs_attention_never_carries_a_token_link_and_leaves_upcoming_stories_out(): void
    {
        $post = $this->dueStory($this->owner);
        $this->issueToken($this->openHandoff($post));
        $this->storyPost(utc: '2026-11-20 16:30:00', postAttributes: ['assignee_id' => $this->owner->id]);

        $response = $this->actingAs($this->owner)->get(route('needs-attention'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.storiesToPost.total', 1)
                ->where('items.storiesToPost.items.0.post_id', $post->id));

        $this->assertStringNotContainsString('/story/', str_replace('\/', '/', (string) json_encode($response->viewData('page')['props']['items'])));
    }

    public function test_a_missed_story_lands_in_failed_publishes_with_the_story_reason(): void
    {
        $post = $this->dueStory($this->owner);

        $this->travelTo('2026-11-20 12:30:00');
        app(ProcessStoryHandoffsAction::class)();

        $this->assertSame(PostStatus::Failed, $post->refresh()->status);

        $items = app(GetNeedsAttentionItemsAction::class)($this->owner);
        $row = collect($items['failedPublishes']['items'])->firstWhere('post_id', $post->id);

        $this->assertNotNull($row);
        $this->assertSame(PublishFailureReason::StoryNotPosted->message(), $row['reason']);
        $this->assertSame(PublishFailureReason::StoryNotPosted->fix(), $row['fix']);
        $this->assertSame('story_not_posted', $row['story_failure']);
        $this->assertSame('@acmecoffee', $row['handle']);
        $this->assertSame(0, $items['storiesToPost']['total']);
    }

    public function test_an_ordinary_failed_publish_keeps_its_plain_reason(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Failed]);
        $this->addFeedChannel($post)->update(['status' => 'failed', 'error' => 'Token expired']);

        $row = app(GetNeedsAttentionItemsAction::class)($this->owner)['failedPublishes']['items'][0];

        $this->assertSame('Publish failed', $row['reason']);
        $this->assertNull($row['fix']);
        $this->assertNull($row['story_failure']);
    }
}
