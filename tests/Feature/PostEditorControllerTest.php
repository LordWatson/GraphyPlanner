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

    /**
     * A `Role::ClientReviewer` (or any role without `update` on the post) can still open the
     * editor and receives the full post payload (caption, hashtags, targets, assets) even though
     * `can.update` is false — the frontend renders it read-only rather than hiding it.
     */
    public function test_client_reviewer_can_view_but_not_update_their_clients_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $client->id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
            'master_caption' => 'Ready for review',
            // Post::booted() normalizes any leading "#" on save, so the stored/returned value is
            // always bare (see PostNormalizationTest for dedicated coverage of that behavior).
            'hashtags' => ['#launch'],
        ]);

        $response = $this->actingAs($reviewer)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('post.id', $post->id)
            ->where('post.master_caption', 'Ready for review')
            ->where('post.hashtags', ['launch'])
            ->where('can.update', false)
        );
    }

    /**
     * The editor's hashtag picker (Instagram-style autocomplete) is fed by the client's
     * `BrandBrain` "always use" hashtags plus every hashtag already used on the client's other
     * posts, deduped and with any leading "#" stripped.
     */
    public function test_editor_exposes_hashtag_suggestions_from_brand_brain_and_past_posts(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        \App\Models\BrandBrain::factory()->for($client)->create([
            'hashtag_policy' => ['always_use' => ['#brandalways', 'shared'], 'never_use' => [], 'rotation_notes' => null],
        ]);
        Post::factory()->for($client)->create(['org_id' => $org->id, 'hashtags' => ['shared', 'pastpost']]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('hashtagSuggestions', ['brandalways', 'shared', 'pastpost'])
        );
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

    /**
     * A `scheduled` or `published` post is locked for content edits: the editor still opens
     * (`view` isn't affected), but `can.update` must be false so the frontend renders it
     * read-only until the post is moved to a different status.
     */
    public function test_scheduled_and_published_posts_are_locked_for_editing(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        foreach ([PostStatus::Scheduled, PostStatus::Published] as $status) {
            $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $status]);

            $response = $this->actingAs($owner)->get(route('posts.edit', $post));

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->where('post.id', $post->id)
                ->where('can.update', false)
            );
        }
    }

    /**
     * Even a direct `PUT posts.update` request must be rejected while the post is `scheduled` or
     * `published`, since `UpdatePostRequest` authorizes via the same `PostPolicy::update`.
     */
    public function test_updating_a_scheduled_or_published_post_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        foreach ([PostStatus::Scheduled, PostStatus::Published] as $status) {
            $post = Post::factory()->for($client)->create([
                'org_id' => $org->id,
                'status' => $status,
                'master_caption' => 'Original caption',
            ]);

            $response = $this->actingAs($owner)->put(route('posts.update', $post), [
                'master_caption' => 'Trying to sneak an edit in',
            ]);

            $response->assertForbidden();
            $this->assertSame('Original caption', $post->refresh()->master_caption);
        }
    }
}
