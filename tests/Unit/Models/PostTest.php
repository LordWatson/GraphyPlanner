<?php

namespace Tests\Unit\Models;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test: some users type hashtags as "#launch", others as "launch". `Post` must
     * normalize every hashtag on save (strip any leading "#", trim whitespace, drop empties/
     * duplicates) so the stored value — and everything that renders/sends it downstream — is
     * always consistent regardless of how it was entered.
     */
    public function test_hashtags_are_normalized_on_save(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'hashtags' => ['#Launch', ' reels ', '##promo', 'Launch', ''],
        ]);

        $this->assertSame(['Launch', 'reels', 'promo'], $post->fresh()->hashtags);
    }

    public function test_null_hashtags_are_left_untouched(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'hashtags' => null,
        ]);

        $this->assertNull($post->fresh()->hashtags);
    }
}
