<?php

namespace Tests\Feature\Posts;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-5.2 — duplicating a post copies its content, media and target accounts into a fresh draft,
 * never its schedule, status or publish state.
 */
class DuplicatePostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function richPost(): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Published,
            'master_caption' => 'Launch day!',
            'hashtags' => ['launch', 'new'],
            'first_comment' => 'Link in bio',
        ]);

        $asset = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->assets()->attach($asset->id, ['sort_order' => 0, 'alt_text' => 'The product']);

        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'caption_override' => 'Channel caption',
            'scheduled_local_date' => now()->addDays(2)->toDateString(),
            'scheduled_local_time' => '09:00:00',
            'status' => PostTargetStatus::Published,
            'external_post_id' => 'ext-123',
        ]);

        return $post;
    }

    public function test_duplicating_copies_content_media_and_targets_but_not_schedule_or_status(): void
    {
        $source = $this->richPost();

        $this->actingAs($this->owner)
            ->post(route('posts.duplicate', $source))
            ->assertRedirect();

        $copy = Post::query()->where('id', '!=', $source->id)->latest('id')->firstOrFail();

        // Content copied.
        $this->assertSame('Launch day!', $copy->master_caption);
        $this->assertSame(['launch', 'new'], $copy->hashtags);
        $this->assertSame('Link in bio', $copy->first_comment);
        $this->assertSame($this->client->id, $copy->client_id);

        // A fresh draft, created by the actor — never the source's published status.
        $this->assertSame(PostStatus::Draft, $copy->status);
        $this->assertSame($this->owner->id, $copy->created_by);

        // Media copied with its pivot.
        $copiedAsset = $copy->assets()->first();
        $this->assertNotNull($copiedAsset);
        $this->assertSame('The product', $copiedAsset->pivot->alt_text);

        // Target account + override copied; schedule and publish state are not.
        $target = $copy->targets()->firstOrFail();
        $this->assertSame('Channel caption', $target->caption_override);
        $this->assertNull($target->scheduled_local_date);
        $this->assertNull($target->external_post_id);
        $this->assertSame(PostTargetStatus::Pending, $target->status);

        // The source is untouched.
        $this->assertSame(PostStatus::Published, $source->refresh()->status);
    }

    public function test_duplicating_lands_on_the_new_posts_editor(): void
    {
        $source = $this->richPost();

        $response = $this->actingAs($this->owner)->post(route('posts.duplicate', $source));

        $copy = Post::query()->where('id', '!=', $source->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('posts.edit', $copy));
    }

    public function test_a_viewer_cannot_duplicate(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();
        $source = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($viewer)
            ->post(route('posts.duplicate', $source))
            ->assertForbidden();

        $this->assertSame(1, Post::query()->count());
    }
}
