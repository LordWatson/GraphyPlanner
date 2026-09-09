<?php

namespace Tests\Feature;

use App\Enums\AssetSource;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostEditorControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Step 0.10 editor page exposes the full payload it needs: checklist, target accounts,
     * available assets, and only the transitions the acting user is authorized to make.
     */
    public function test_editor_page_exposes_post_checklist_and_allowed_transitions(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($owner)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('post.id', $post->id)
            ->where('post.checklist.passed', false)
            ->has('allowedTransitions', 2)
            ->where('can.update', true)
        );
    }

    /**
     * Saving the editor form replaces the post's targets and attached media, and refreshes the
     * §6 checklist snapshot.
     */
    public function test_update_replaces_targets_and_assets_and_refreshes_checklist(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'master_caption' => null]);

        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);
        $asset = Asset::factory()->for($client)->create([
            'org_id' => $org->id,
            'source' => AssetSource::Url,
            'url' => 'https://example.com/image.png',
        ]);

        $response = $this->actingAs($owner)->put(route('posts.update', $post), [
            'master_caption' => 'Updated caption',
            'review_message' => 'Please double-check the hashtags before approving.',
            'asset_ids' => [$asset->id],
            'targets' => [
                [
                    'social_account_id' => $account->id,
                    'scheduled_local_date' => '2026-10-05',
                    'scheduled_local_time' => '10:00',
                ],
            ],
        ]);

        $response->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('Updated caption', $post->master_caption);
        $this->assertSame('Please double-check the hashtags before approving.', $post->review_message);
        $this->assertSame([$asset->id], $post->assets()->pluck('assets.id')->all());

        $target = $post->targets()->firstOrFail();
        $this->assertSame($account->id, $target->social_account_id);
        $this->assertNotNull($target->scheduled_at_utc);

        // Caption + target + media all present now, so every checklist item should pass.
        $this->assertTrue($post->checklist_snapshot['passed']);
    }

    public function test_designer_from_another_org_cannot_open_the_editor(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $otherDesigner = User::factory()->for($otherOrg, 'organization')->role(Role::Designer)->create();

        $response = $this->actingAs($otherDesigner)->get(route('posts.edit', $post));

        $response->assertForbidden();
    }
}
