<?php

namespace Tests\Unit\Services;

use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\ClientHashtag;
use App\Models\Post;
use App\Models\User;
use App\Services\ClientHashtagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step I-2.6 — the `client_hashtags` vocabulary follows every post write.
 */
class ClientHashtagServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_posts_counts_each_tag_once_per_post_case_insensitively(): void
    {
        $client = Client::factory()->create();
        Post::factory()->for($client)->create(['org_id' => $client->org_id, 'hashtags' => ['#Launch', 'summer']]);
        Post::factory()->for($client)->create(['org_id' => $client->org_id, 'hashtags' => ['launch']]);

        $this->assertSame(2, $this->uses($client, 'launch'));
        $this->assertSame(1, $this->uses($client, 'summer'));
        $this->assertSame('Launch', ClientHashtag::query()->where('tag_key', 'launch')->value('tag'));
    }

    public function test_editing_and_deleting_a_post_moves_the_counts(): void
    {
        $client = Client::factory()->create();
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'hashtags' => ['launch', 'summer']]);

        $post->update(['hashtags' => ['launch', 'autumn']]);

        $this->assertSame(1, $this->uses($client, 'launch'));
        $this->assertSame(0, $this->uses($client, 'summer'));
        $this->assertSame(1, $this->uses($client, 'autumn'));

        $post->delete();

        $this->assertSame(0, $this->uses($client, 'launch'));

        $post->restore();

        $this->assertSame(1, $this->uses($client, 'launch'));

        $post->delete();
        $post->forceDelete();

        $this->assertSame(0, $this->uses($client, 'launch'), 'Purging a trashed post must not count it out twice.');
    }

    public function test_suggestions_rank_by_usage_then_recency_and_skip_unused_tags(): void
    {
        $client = Client::factory()->create();
        Post::factory()->for($client)->count(3)->create(['org_id' => $client->org_id, 'hashtags' => ['popular']]);
        Post::factory()->for($client)->create(['org_id' => $client->org_id, 'hashtags' => ['niche']]);
        $gone = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'hashtags' => ['gone']]);
        $gone->update(['hashtags' => []]);

        Post::factory()->create(['hashtags' => ['other-client']]);

        $this->assertSame(['popular', 'niche'], app(ClientHashtagService::class)->suggestions($client));
    }

    public function test_the_editor_suggests_brand_brain_tags_first_then_the_vocabulary(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['org_id' => $user->org_id]);
        BrandBrain::factory()->for($client)->create(['hashtag_policy' => ['always_use' => ['#brand']]]);
        $post = Post::factory()->for($client)->create(['org_id' => $user->org_id, 'hashtags' => ['launch', 'brand']]);

        $this->actingAs($user)
            ->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page->where('hashtagSuggestions', ['brand', 'launch']));
    }

    private function uses(Client $client, string $key): int
    {
        return (int) ClientHashtag::query()->where('client_id', $client->id)->where('tag_key', $key)->value('uses');
    }
}
