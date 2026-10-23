<?php

namespace Tests\Feature\AI;

use App\Actions\AI\RepurposeCaptionsForPostAction;
use App\Contracts\CaptionRepurposer;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepurposeCaptionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, Platform>  $platforms
     * @return array{0: Organization, 1: Client, 2: Post, 3: User}
     */
    private function postWithTargets(array $platforms = [Platform::Instagram, Platform::TikTok]): array
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        BrandBrain::factory()->for($client)->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'master_caption' => 'Our sourdough is ready.']);

        foreach ($platforms as $platform) {
            $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => $platform]);
            PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
        }

        return [$org, $client, $post, $owner];
    }

    /**
     * A fake repurposer returning fixed variants (optionally over-limit) per platform.
     *
     * @param  array<string, array{caption: string, hashtags: list<string>, firstComment: string|null}>  $variants
     */
    private function fakeRepurposer(array $variants): void
    {
        $this->app->instance(CaptionRepurposer::class, new class($variants) implements CaptionRepurposer
        {
            public function __construct(private readonly array $variants) {}

            public function repurpose(Post $post, array $platforms): array
            {
                return $this->variants;
            }
        });
    }

    public function test_it_clamps_each_variant_to_the_platforms_limits(): void
    {
        [, , $post] = $this->postWithTargets();

        $this->fakeRepurposer([
            'instagram' => ['caption' => str_repeat('a', Platform::Instagram->captionLimit() + 500), 'hashtags' => array_fill(0, Platform::Instagram->maxHashtags() + 10, 'tag'), 'firstComment' => null],
            'tiktok' => ['caption' => str_repeat('b', Platform::TikTok->captionLimit() + 500), 'hashtags' => [], 'firstComment' => null],
        ]);

        $result = app(RepurposeCaptionsForPostAction::class)($post);

        $this->assertLessThanOrEqual(Platform::Instagram->captionLimit(), mb_strlen($result['instagram']['caption']));
        $this->assertLessThanOrEqual(Platform::Instagram->maxHashtags(), count($result['instagram']['hashtags']));
        $this->assertLessThanOrEqual(Platform::TikTok->captionLimit(), mb_strlen($result['tiktok']['caption']));
    }

    public function test_it_returns_one_variant_per_platform_and_records_usage(): void
    {
        [, $client, $post] = $this->postWithTargets();

        $this->fakeRepurposer([
            'instagram' => ['caption' => 'IG caption', 'hashtags' => ['bakery'], 'firstComment' => null],
            'tiktok' => ['caption' => 'TikTok hook', 'hashtags' => [], 'firstComment' => 'More below'],
        ]);

        $result = app(RepurposeCaptionsForPostAction::class)($post);

        $this->assertArrayHasKey('instagram', $result);
        $this->assertArrayHasKey('tiktok', $result);
        $this->assertSame('TikTok', $result['tiktok']['platform_label']);
        $this->assertDatabaseHas('ai_usage_logs', ['client_id' => $client->id, 'method' => 'repurpose']);
    }

    public function test_a_banned_phrase_variant_is_dropped(): void
    {
        [, $client, $post] = $this->postWithTargets();
        $client->brandBrain->update(['voice' => ['do_nots' => ['cheap']]]);

        $this->fakeRepurposer([
            'instagram' => ['caption' => 'Our cheap bread special.', 'hashtags' => [], 'firstComment' => null],
            'tiktok' => ['caption' => 'A proper hook.', 'hashtags' => [], 'firstComment' => null],
        ]);

        $result = app(RepurposeCaptionsForPostAction::class)($post->refresh());

        $this->assertArrayNotHasKey('instagram', $result);
        $this->assertArrayHasKey('tiktok', $result);
    }

    public function test_the_endpoint_returns_variants_for_an_owner(): void
    {
        config(['ai.enabled' => true]);
        [, , $post, $owner] = $this->postWithTargets();
        $this->fakeRepurposer([
            'instagram' => ['caption' => 'IG', 'hashtags' => [], 'firstComment' => null],
            'tiktok' => ['caption' => 'TT', 'hashtags' => [], 'firstComment' => null],
        ]);

        $this->actingAs($owner)
            ->postJson(route('posts.ai.repurpose', $post))
            ->assertOk()
            ->assertJsonPath('variants.instagram.caption', 'IG');
    }

    public function test_the_endpoint_is_404_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        [, , $post, $owner] = $this->postWithTargets();

        $this->actingAs($owner)->postJson(route('posts.ai.repurpose', $post))->assertNotFound();
    }

    public function test_a_designer_is_forbidden(): void
    {
        config(['ai.enabled' => true]);
        [$org, , $post] = $this->postWithTargets();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)->postJson(route('posts.ai.repurpose', $post))->assertForbidden();
    }
}
