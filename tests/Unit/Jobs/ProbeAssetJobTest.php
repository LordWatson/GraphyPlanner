<?php

namespace Tests\Unit\Jobs;

use App\Contracts\MediaProber;
use App\Enums\AssetType;
use App\Jobs\ProbeAssetJob;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\Media\VideoProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Step I-3.5 / M-2.4 — uploads are probed for their real size, a video's length and codec, and a
 * thumbnail for lists and grids.
 */
class ProbeAssetJobTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assets.disk' => 'public']);
        Storage::fake('public');
        $org = Organization::factory()->create();
        $this->client = Client::factory()->for($org, 'organization')->create();
    }

    private function storedAsset(AssetType $type, string $path, string $bytes, string $mime): Asset
    {
        Storage::disk('public')->put($path, $bytes);

        return Asset::factory()->uploaded()->for($this->client)->create([
            'org_id' => $this->client->org_id,
            'type' => $type,
            'disk' => 'public',
            'path' => $path,
            'mime_type' => $mime,
            'width' => null,
            'height' => null,
        ]);
    }

    public function test_an_image_gets_its_size_and_a_small_thumbnail(): void
    {
        $asset = $this->storedAsset(AssetType::Image, 'assets/photo.jpg', (string) ImageManager::gd()->create(2000, 1000)->fill('205557')->toJpeg(), 'image/jpeg');

        app()->call([new ProbeAssetJob($asset->id), 'handle']);

        $asset->refresh();
        $this->assertSame(2000, $asset->width);
        $this->assertSame(1000, $asset->height);
        $this->assertNotNull($asset->probed_at);
        $this->assertNotNull($asset->thumbnail_path);
        Storage::disk('public')->assertExists((string) $asset->thumbnail_path);

        $thumbnail = ImageManager::gd()->read(Storage::disk('public')->get((string) $asset->thumbnail_path));
        $this->assertSame(ProbeAssetJob::THUMBNAIL_LONG_EDGE, $thumbnail->width());
        $this->assertStringContainsString((string) $asset->thumbnail_path, (string) $asset->thumbnailUrl());
    }

    public function test_a_video_is_probed_through_the_media_prober_and_gets_a_frame_thumbnail(): void
    {
        $frame = (string) ImageManager::gd()->create(1080, 1920)->fill('181723')->toJpeg();
        $this->app->instance(MediaProber::class, new class($frame) implements MediaProber
        {
            public function __construct(private string $frame) {}

            public function probe(string $localPath): ?VideoProbe
            {
                return new VideoProbe(width: 1080, height: 1920, durationMs: 42_500, codec: 'h264');
            }

            public function frame(string $localPath, int $atMs = 1000): ?string
            {
                return $this->frame;
            }
        });

        $asset = $this->storedAsset(AssetType::Video, 'assets/clip.mp4', 'not really a video', 'video/mp4');

        app()->call([new ProbeAssetJob($asset->id), 'handle']);

        $asset->refresh();
        $this->assertSame([1080, 1920, 42_500, 'h264'], [$asset->width, $asset->height, $asset->duration_ms, $asset->video_codec]);
        $this->assertNotNull($asset->thumbnail_path);
    }

    public function test_a_video_the_prober_cant_read_is_marked_probed_without_guessing(): void
    {
        $this->app->instance(MediaProber::class, new class implements MediaProber
        {
            public function probe(string $localPath): ?VideoProbe
            {
                return null;
            }

            public function frame(string $localPath, int $atMs = 1000): ?string
            {
                return null;
            }
        });

        $asset = $this->storedAsset(AssetType::Video, 'assets/broken.mp4', 'x', 'video/mp4');

        app()->call([new ProbeAssetJob($asset->id), 'handle']);

        $asset->refresh();
        $this->assertNotNull($asset->probed_at);
        $this->assertNull($asset->duration_ms);
        $this->assertNull($asset->thumbnail_path);
        $this->assertNull($asset->thumbnailUrl(), 'A video without a thumbnail never falls back to loading the video.');
    }

    public function test_uploading_an_asset_queues_the_probe(): void
    {
        Queue::fake();
        $owner = User::factory()->for($this->client->organization, 'organization')->create();

        $this->actingAs($owner)->post(route('clients.assets.store', $this->client), [
            'source' => 'upload',
            'file' => UploadedFile::fake()->image('hero.jpg', 800, 600),
        ]);

        Queue::assertPushed(ProbeAssetJob::class);
    }
}
