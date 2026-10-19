<?php

namespace Tests\Feature\Posts;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\AssetType;
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

/**
 * The editor's image format (per channel) and framing (per attached image): saved with the post,
 * validated against what each channel offers, and sent to every preview — editor, review link
 * and client portal — so they all frame media the way the publisher crops it.
 */
class PostMediaFormatTest extends TestCase
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

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create(['org_id' => $this->org->id, ...$attributes]);
    }

    private function image(array $attributes = []): Asset
    {
        return Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'type' => AssetType::Image,
            'width' => 1600,
            'height' => 900,
            ...$attributes,
        ]);
    }

    public function test_the_editor_sends_formats_framing_and_the_options_per_channel(): void
    {
        $post = $this->makePost(['media_format' => ['instagram' => '4:5']]);
        $asset = $this->image();
        $post->assets()->attach($asset, ['crop_x' => 0.25, 'crop_y' => 0.75, 'crop_zoom' => 1.5]);

        $this->actingAs($this->owner)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('post.media_format.instagram', '4:5')
                ->where('post.media_format.tiktok', 'original')
                ->where('post.media_format.facebook', 'original')
                ->where('post.media_format.linkedin', 'original')
                ->where('post.assets.0.width', 1600)
                ->where('post.assets.0.height', 900)
                ->where('post.assets.0.crop', ['x' => 0.25, 'y' => 0.75, 'zoom' => 1.5])
                ->has('mediaFormatOptions.instagram', 5)
                ->where('mediaFormatOptions.tiktok.1', ['value' => '9:16', 'label' => 'Full screen'])
            );
    }

    public function test_saving_stores_the_format_and_each_images_framing(): void
    {
        $post = $this->makePost();
        $first = $this->image();
        $second = $this->image();

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'media_format' => ['instagram' => '4:5', 'tiktok' => '9:16', 'facebook' => 'original', 'linkedin' => ''],
            'asset_ids' => [$first->id, $second->id],
            'asset_crops' => [
                $first->id => ['x' => '0.12345', 'y' => '0.9', 'zoom' => '2.25'],
            ],
        ])->assertRedirect(route('posts.edit', $post))->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame(['instagram' => '4:5', 'tiktok' => '9:16', 'facebook' => 'original'], $post->media_format);

        $assets = $post->assets()->get()->keyBy('id');
        $this->assertEqualsWithDelta(0.1235, (float) $assets[$first->id]->pivot->crop_x, 1e-6);
        $this->assertEqualsWithDelta(0.9, (float) $assets[$first->id]->pivot->crop_y, 1e-6);
        $this->assertEqualsWithDelta(2.25, (float) $assets[$first->id]->pivot->crop_zoom, 1e-6);
        // Attached without framing: centred at 1×.
        $this->assertEqualsWithDelta(0.5, (float) $assets[$second->id]->pivot->crop_x, 1e-6);
        $this->assertEqualsWithDelta(1.0, (float) $assets[$second->id]->pivot->crop_zoom, 1e-6);
    }

    public function test_saving_media_without_framing_keeps_the_existing_framing(): void
    {
        $post = $this->makePost();
        $asset = $this->image();
        $post->assets()->attach($asset, ['crop_x' => 0.1, 'crop_y' => 0.2, 'crop_zoom' => 1.8]);

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'master_caption' => 'Only the caption changed',
            'asset_ids' => [$asset->id],
        ])->assertSessionHasNoErrors();

        $pivot = $post->assets()->firstOrFail()->pivot;
        $this->assertEqualsWithDelta(0.1, (float) $pivot->crop_x, 1e-6);
        $this->assertEqualsWithDelta(1.8, (float) $pivot->crop_zoom, 1e-6);
    }

    public function test_an_all_default_format_is_stored_as_null(): void
    {
        $post = $this->makePost(['media_format' => ['instagram' => '1:1']]);

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'media_format' => ['instagram' => '', 'tiktok' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertNull($post->refresh()->media_format);
        $this->assertSame('original', $post->mediaRatioFor(Platform::Instagram)->value);
    }

    public function test_a_format_the_channel_does_not_offer_is_rejected(): void
    {
        $post = $this->makePost();

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'media_format' => ['instagram' => '9:16'],
        ])->assertSessionHasErrors('media_format.instagram');

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'media_format' => ['myspace' => '1:1'],
        ])->assertSessionHasErrors('media_format');

        $this->assertNull($post->refresh()->media_format);
    }

    public function test_framing_must_be_in_range_and_for_attached_media_only(): void
    {
        $post = $this->makePost();
        $attached = $this->image();
        $other = $this->image();

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'asset_ids' => [$attached->id],
            'asset_crops' => [$attached->id => ['x' => 1.5, 'y' => 0.5, 'zoom' => 4]],
        ])->assertSessionHasErrors(["asset_crops.{$attached->id}.x", "asset_crops.{$attached->id}.zoom"]);

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'asset_ids' => [$attached->id],
            'asset_crops' => [$other->id => ['x' => 0.5, 'y' => 0.5, 'zoom' => 1]],
        ])->assertSessionHasErrors("asset_crops.{$other->id}");

        $this->assertSame(0, $post->assets()->count());
    }

    /**
     * Regression: removing the last attached item sends `asset_ids` empty (the browser omits an
     * empty list), which must still detach it rather than silently keep it.
     */
    public function test_saving_with_an_empty_media_field_detaches_everything(): void
    {
        $post = $this->makePost();
        $post->assets()->attach($this->image());

        $this->actingAs($this->owner)->put(route('posts.update', $post), [
            'asset_ids' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $post->assets()->count());
    }

    public function test_the_review_link_and_client_portal_preview_the_saved_framing(): void
    {
        $post = $this->makePost([
            'status' => PostStatus::WaitingClient,
            'media_format' => ['facebook' => '1:1'],
        ]);
        $post->assets()->attach($this->image(), ['crop_x' => 0.3, 'crop_y' => 0.4, 'crop_zoom' => 1.2]);
        SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::Facebook]);

        ['token' => $token] = (new CreateReviewTokenAction)($this->client, $post);

        $this->get(route('review.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('post.media_format.facebook', '1:1')
                ->where('post.assets.0.crop', ['x' => 0.3, 'y' => 0.4, 'zoom' => 1.2])
            );

        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);

        $this->actingAs($reviewer)->get(route('portal.posts.show', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('post.media_format.facebook', '1:1')
                ->where('post.assets.0.width', 1600)
                ->where('post.assets.0.crop.zoom', 1.2)
            );
    }
}
