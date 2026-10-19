<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\AssetType;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Steps I-3.2 / I-3.5 (M-2.3 / M-2.4) — an explicit format per channel (feed, reel, story,
 * carousel, short), carousel order, video covers, and media checked against each format before
 * anything is scheduled.
 */
class PostFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['upload_post_key' => 'key']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    private function account(Platform $platform): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
            'handle' => '@'.$platform->value,
        ]);
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'master_caption' => 'New season.',
            'hashtags' => [],
            ...$attributes,
        ]);
    }

    private function asset(AssetType $type, array $attributes = []): Asset
    {
        return Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'type' => $type,
            'url' => 'https://cdn.test/'.fake()->uuid().($type === AssetType::Video ? '.mp4' : '.jpg'),
            'disk' => null,
            'path' => null,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function fields(Request $request): array
    {
        $fields = [];

        foreach ($request->data() as $name => $part) {
            if (is_array($part) && isset($part['name'])) {
                $fields[$part['name']][] = (string) $part['contents'];
            } else {
                $fields[(string) $name][] = is_array($part) ? (string) json_encode($part) : (string) $part;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checklist(Post $post): array
    {
        return collect((new EvaluatePostChecklistAction)($post->refresh())['items'])->keyBy('key')->all();
    }

    public function test_an_instagram_story_publishes_as_stories_with_the_image_cut_to_9_16(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('assets/square.jpg', (string) ImageManager::gd()->create(1200, 1200)->fill('205557')->toJpeg());
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'story-1'])]);

        $account = $this->account(Platform::Instagram);
        $post = $this->makePost();
        $image = Asset::factory()->uploaded()->for($this->client)->create([
            'org_id' => $this->org->id, 'type' => AssetType::Image, 'mime_type' => 'image/jpeg', 'path' => 'assets/square.jpg',
        ]);
        $post->assets()->attach($image);
        $post->targets()->create(['social_account_id' => $account->id, 'format' => 'story']);

        (new UploadPostAdapter)->publish($post->load('targets.socialAccount', 'assets'), collect([$account]));

        Http::assertSent(function (Request $request) {
            $fields = self::fields($request);
            $photo = ImageManager::gd()->read($fields['photos[]'][0]);

            return $fields['media_type'] === ['STORIES']
                && abs($photo->width() / $photo->height() - 9 / 16) < 0.01;
        });
    }

    public function test_a_carousel_publishes_its_slides_in_the_saved_order(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'c-1'])]);

        $account = $this->account(Platform::Instagram);
        $post = $this->makePost();
        $first = $this->asset(AssetType::Image, ['url' => 'https://cdn.test/first.jpg']);
        $second = $this->asset(AssetType::Image, ['url' => 'https://cdn.test/second.jpg']);
        $third = $this->asset(AssetType::Image, ['url' => 'https://cdn.test/third.jpg']);
        $post->assets()->attach([$third->id => ['sort_order' => 2], $first->id => ['sort_order' => 0], $second->id => ['sort_order' => 1]]);
        $post->targets()->create(['social_account_id' => $account->id]);

        (new UploadPostAdapter)->publish($post->load('targets.socialAccount', 'assets'), collect([$account]));

        Http::assertSent(fn (Request $request) => self::fields($request)['photos[]'] === [
            'https://cdn.test/first.jpg', 'https://cdn.test/second.jpg', 'https://cdn.test/third.jpg',
        ] && self::fields($request)['media_type'] === ['IMAGE']);
    }

    public function test_saving_media_in_a_new_order_reorders_the_carousel(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $post = $this->makePost();
        $a = $this->asset(AssetType::Image);
        $b = $this->asset(AssetType::Image);

        $this->actingAs($owner)->put(route('posts.update', $post), ['asset_ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();

        $this->assertSame([$b->id, $a->id], $post->refresh()->assets->pluck('id')->all());
    }

    public function test_facebook_formats_and_a_video_cover_reach_the_vendor(): void
    {
        Http::fake(['*/upload' => Http::response(['request_id' => 'v-1'])]);

        $facebook = $this->account(Platform::Facebook);
        $instagram = $this->account(Platform::Instagram);
        $cover = $this->asset(AssetType::Image, ['url' => 'https://cdn.test/cover.jpg']);
        $post = $this->makePost(['cover_asset_id' => $cover->id, 'thumb_offset_ms' => 2500]);
        $post->assets()->attach($this->asset(AssetType::Video, ['width' => 1080, 'height' => 1920, 'duration_ms' => 20_000]));
        $post->targets()->create(['social_account_id' => $facebook->id, 'format' => 'story']);
        $post->targets()->create(['social_account_id' => $instagram->id]);

        (new UploadPostAdapter)->publish($post->load('targets.socialAccount', 'assets'), collect([$facebook, $instagram]));

        Http::assertSent(fn (Request $request) => (self::fields($request)['platform[]'] ?? null) === ['facebook']
            && self::fields($request)['facebook_media_type'] === ['STORIES']
            && self::fields($request)['thumbnail_url'] === ['https://cdn.test/cover.jpg']);

        Http::assertSent(fn (Request $request) => (self::fields($request)['platform[]'] ?? null) === ['instagram']
            && self::fields($request)['media_type'] === ['REELS']
            && self::fields($request)['cover_url'] === ['https://cdn.test/cover.jpg']
            && self::fields($request)['thumb_offset'] === ['2500']);
    }

    public function test_the_checklist_rejects_a_format_that_doesnt_fit_the_media_and_offers_one_that_does(): void
    {
        $account = $this->account(Platform::Instagram);
        $post = $this->makePost();
        $post->assets()->attach([$this->asset(AssetType::Image)->id, $this->asset(AssetType::Image)->id, $this->asset(AssetType::Image)->id]);
        $post->targets()->create([
            'social_account_id' => $account->id, 'format' => 'story',
            'scheduled_local_date' => '2030-01-01', 'scheduled_local_time' => '09:00',
        ]);

        $items = $this->checklist($post);

        $this->assertFalse($items['format']['passed']);
        $this->assertStringContainsString('Instagram (@instagram): a story takes 1 item — this post sends 3.', $items['format']['detail']);
        $this->assertSame([[
            'action' => 'format',
            'social_account_id' => $account->id,
            'value' => 'carousel',
            'label' => 'Post to Instagram (@instagram) as a carousel',
        ]], $items['format']['fixes']);

        $post->targets()->update(['format' => 'carousel']);
        $this->assertTrue($this->checklist($post)['format']['passed']);

        $post->targets()->update(['format' => 'reel']);
        $this->assertStringContainsString('a reel takes a video — this post sends 3 images.', $this->checklist($post)['format']['detail']);
    }

    public function test_an_over_length_tiktok_and_a_landscape_story_are_blocked_with_a_specific_reason(): void
    {
        $tiktok = $this->account(Platform::TikTok);
        $instagram = $this->account(Platform::Instagram);
        $post = $this->makePost();
        $post->assets()->attach($this->asset(AssetType::Video, [
            'width' => 1920, 'height' => 1080, 'duration_ms' => 750_000, 'original_filename' => 'launch.mp4',
        ]));
        $post->targets()->create(['social_account_id' => $tiktok->id, 'scheduled_local_date' => '2030-01-01', 'scheduled_local_time' => '09:00']);
        $post->targets()->create(['social_account_id' => $instagram->id, 'format' => 'story', 'scheduled_local_date' => '2030-01-01', 'scheduled_local_time' => '09:00']);

        $result = (new EvaluatePostChecklistAction)($post->refresh());
        $items = collect($result['items'])->keyBy('key');

        $this->assertFalse($result['passed']);
        $this->assertFalse($items['media_fit']['passed']);
        $this->assertStringContainsString('TikTok (@tiktok): the video is 12:30 long — a video can be up to 10:00.', $items['media_fit']['detail']);
        $this->assertStringContainsString('Instagram (@instagram): a story needs vertical 9:16 video — this one is 16:9.', $items['media_fit']['detail']);
        // The story's 750s video is also over the story limit.
        $this->assertStringContainsString('a story can be up to 1:00', $items['media_fit']['detail']);
    }

    public function test_tiktok_photo_mode_offers_a_one_click_crop_to_fill_the_screen(): void
    {
        $tiktok = $this->account(Platform::TikTok);
        $post = $this->makePost();
        $post->assets()->attach($this->asset(AssetType::Image, ['width' => 1600, 'height' => 900]));
        $post->targets()->create(['social_account_id' => $tiktok->id]);

        $items = $this->checklist($post);

        $this->assertFalse($items['media_framing']['passed']);
        $this->assertTrue($items['media_framing']['soft']);
        $this->assertSame('media_format', $items['media_framing']['fixes'][0]['action']);
        $this->assertSame('9:16', $items['media_framing']['fixes'][0]['value']);

        $post->update(['media_format' => ['tiktok' => '9:16']]);
        $this->assertTrue($this->checklist($post)['media_framing']['passed']);
    }

    public function test_unprobed_media_is_never_blocked_on_checks_it_cant_make(): void
    {
        $tiktok = $this->account(Platform::TikTok);
        $post = $this->makePost();
        $post->assets()->attach($this->asset(AssetType::Video, ['width' => null, 'height' => null, 'duration_ms' => null]));
        $post->targets()->create(['social_account_id' => $tiktok->id]);

        $this->assertTrue($this->checklist($post)['media_fit']['passed']);
    }
}
