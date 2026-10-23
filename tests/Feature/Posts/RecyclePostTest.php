<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\GenerateRecurringPostDraftsAction;
use App\Actions\Posts\RecyclePostAction;
use App\Enums\PostRecurrence;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Step M-5.8 (Phase 11 / recurring posts) — recycling an evergreen post and the daily recurrence
 * sweep that drops the next occurrence as a draft.
 */
class RecyclePostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-17 12:00:00');

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    public function test_manual_recycle_copies_content_and_accounts_as_a_fresh_idea_with_no_schedule(): void
    {
        $source = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Published,
            'master_caption' => 'Evergreen tip',
            'hashtags' => ['tips'],
            'is_recyclable' => true,
        ]);
        $asset = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $source->assets()->attach($asset->id, ['sort_order' => 0, 'alt_text' => 'A tip']);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'UTC']);
        $source->targets()->create([
            'social_account_id' => $account->id,
            'caption_override' => 'Channel tip',
            'scheduled_local_date' => '2026-10-10',
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => '2026-10-10T09:00:00+00:00',
            'status' => PostTargetStatus::Published,
        ]);

        $copy = (new RecyclePostAction)($source, $this->owner);

        $this->assertSame(PostStatus::Idea, $copy->status);
        $this->assertSame('Evergreen tip', $copy->master_caption);
        $this->assertSame($source->id, $copy->recycled_from_post_id);
        $this->assertNull($copy->recurrence);
        $this->assertSame('A tip', $copy->assets()->firstOrFail()->pivot->alt_text);

        // The account + override carry over (I-4.7); the schedule and publish state do not.
        $target = $copy->targets()->firstOrFail();
        $this->assertSame($account->id, $target->social_account_id);
        $this->assertSame('Channel tip', $target->caption_override);
        $this->assertNull($target->scheduled_local_date);
        $this->assertSame(PostTargetStatus::Pending, $target->status);

        // The source is untouched.
        $this->assertSame(PostStatus::Published, $source->refresh()->status);
    }

    public function test_a_non_recyclable_post_cannot_be_recycled_manually(): void
    {
        $source = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Published,
            'is_recyclable' => false,
        ]);

        $this->expectException(ValidationException::class);
        (new RecyclePostAction)($source);
    }

    public function test_recurrence_advances_a_weekly_target_keeping_its_local_time_across_dst(): void
    {
        $source = $this->recurringPost(PostRecurrence::Weekly, 'Europe/London', '2026-10-20', '09:00');

        $draft = (new RecyclePostAction)($source, null, PostRecurrence::Weekly);

        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertSame(PostRecurrence::Weekly, $draft->recurrence);
        $this->assertSame($source->id, $draft->recycled_from_post_id);

        $target = $draft->targets()->firstOrFail();
        $this->assertSame('2026-10-27', $target->scheduled_local_date?->toDateString());
        $this->assertSame('09:00', $target->scheduled_local_time);
        // London leaves BST on 25 Oct, so 09:00 local is now 09:00 UTC, not 08:00.
        $this->assertSame('2026-10-27T09:00:00+00:00', $target->scheduled_at_utc?->toIso8601String());
    }

    public function test_recurrence_is_month_end_safe(): void
    {
        $source = $this->recurringPost(PostRecurrence::Monthly, 'UTC', '2027-01-31', '10:00');

        $draft = (new RecyclePostAction)($source, null, PostRecurrence::Monthly);

        // 31 Jan + 1 month lands on 28 Feb (2027 isn't a leap year), not 3 March.
        $this->assertSame('2027-02-28', $draft->targets()->firstOrFail()->scheduled_local_date?->toDateString());
    }

    public function test_the_sweep_drops_one_draft_per_published_recurring_leaf_and_is_idempotent(): void
    {
        $source = $this->recurringPost(PostRecurrence::Weekly, 'UTC', '2026-10-15', '09:00');

        $this->assertSame(1, (new GenerateRecurringPostDraftsAction)());
        // Running again creates nothing — the leaf now has a recurrence child.
        $this->assertSame(0, (new GenerateRecurringPostDraftsAction)());

        $draft = Post::query()->where('recycled_from_post_id', $source->id)->firstOrFail();
        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertSame('2026-10-22', $draft->targets()->firstOrFail()->scheduled_local_date?->toDateString());
    }

    public function test_a_recurring_post_that_has_not_published_yet_is_not_swept(): void
    {
        $this->recurringPost(PostRecurrence::Weekly, 'UTC', '2026-10-25', '09:00', PostStatus::Scheduled);

        $this->assertSame(0, (new GenerateRecurringPostDraftsAction)());
        $this->assertSame(0, Post::query()->whereNotNull('recycled_from_post_id')->count());
    }

    public function test_the_recycle_endpoint_opens_the_new_draft_and_is_gated(): void
    {
        $post = $this->recyclablePublishedPost();

        // A viewer can't recycle.
        $this->actingAs(User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create())
            ->post(route('posts.recycle', $post))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->post(route('posts.recycle', $post))
            ->assertRedirectToRoute('posts.edit', Post::query()->where('recycled_from_post_id', $post->id)->firstOrFail());
    }

    public function test_a_non_recyclable_post_cannot_be_recycled_via_the_endpoint(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Published,
            'is_recyclable' => false,
        ]);

        $this->actingAs($this->owner)
            ->post(route('posts.recycle', $post))
            ->assertForbidden();
    }

    public function test_the_home_recycle_bucket_lists_posts_past_their_window_only(): void
    {
        // Past its 7-day window → suggested.
        $due = $this->recyclablePublishedPost(windowDays: 7, publishedAt: now()->subDays(10));
        // Still inside its window → not suggested.
        $this->recyclablePublishedPost(windowDays: 30, publishedAt: now()->subDays(2));
        // Past its window but already recycled → not suggested.
        $recycled = $this->recyclablePublishedPost(windowDays: 7, publishedAt: now()->subDays(10));
        (new RecyclePostAction)($recycled, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('needs-attention'))
            ->assertInertia(fn ($page) => $page
                ->has('items.recyclingSuggestions.items', 1)
                ->where('items.recyclingSuggestions.items.0.post_id', $due->id)
                ->where('items.recyclingSuggestions.items.0.reason', 'Ready to recycle'));
    }

    private function recyclablePublishedPost(int $windowDays = 7, ?CarbonInterface $publishedAt = null): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Published,
            'is_recyclable' => true,
            'recycle_after_days' => $windowDays,
            'master_caption' => 'Evergreen',
        ]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'UTC']);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'published_at' => $publishedAt ?? now()->subDays($windowDays + 1),
        ]);

        return $post;
    }

    private function recurringPost(
        PostRecurrence $recurrence,
        string $timezone,
        string $date,
        string $time,
        PostStatus $status = PostStatus::Published,
    ): Post {
        $source = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => $status,
            'recurrence' => $recurrence,
            'master_caption' => 'Weekly roundup',
        ]);
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'timezone' => $timezone,
        ]);
        $source->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => $date,
            'scheduled_local_time' => $time,
            'scheduled_at_utc' => Carbon::parse($date.' '.$time, $timezone)->utc(),
            'status' => $status === PostStatus::Published ? PostTargetStatus::Published : PostTargetStatus::Pending,
        ]);

        return $source;
    }
}
