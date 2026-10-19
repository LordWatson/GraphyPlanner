<?php

namespace Tests\Unit\Services\Media;

use App\Enums\AssetType;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Post;
use App\Services\Media\PublishImageCropper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class PublishImageCropperTest extends TestCase
{
    use RefreshDatabase;

    private static function jpeg(int $width, int $height): string
    {
        return (string) ImageManager::gd()->create($width, $height)->fill('205557')->toJpeg();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function pixelSize(string $bytes): array
    {
        $image = ImageManager::gd()->read($bytes);

        return [$image->width(), $image->height()];
    }

    private function attachedImage(Post $post, array $pivot = []): Asset
    {
        $asset = Asset::factory()->create([
            'client_id' => $post->client_id,
            'type' => AssetType::Image,
            'mime_type' => 'image/jpeg',
        ]);
        $post->assets()->attach($asset, $pivot);

        return $post->assets()->findOrFail($asset->id);
    }

    public function test_a_fixed_format_is_the_target_ratio_on_that_channel(): void
    {
        $post = Post::factory()->create(['media_format' => ['instagram' => '4:5']]);
        $cropper = new PublishImageCropper;

        $this->assertEqualsWithDelta(0.8, $cropper->targetRatio($post, Platform::Instagram, [1000, 1000]), 1e-9);
        $this->assertNull($cropper->targetRatio($post, Platform::Facebook, [1000, 1000]));
    }

    public function test_an_instagram_original_is_clamped_into_the_accepted_range(): void
    {
        $post = Post::factory()->create();
        $cropper = new PublishImageCropper;

        $this->assertEqualsWithDelta(0.8, $cropper->targetRatio($post, Platform::Instagram, [1000, 3000]), 1e-9);
        $this->assertEqualsWithDelta(1.91, $cropper->targetRatio($post, Platform::Instagram, [4000, 1000]), 1e-9);
        // In range: the first image's own shape, which every carousel slide then follows.
        $this->assertEqualsWithDelta(1.5, $cropper->targetRatio($post, Platform::Instagram, [1500, 1000]), 1e-9);
        $this->assertNull($cropper->targetRatio($post, Platform::Instagram, null));
    }

    public function test_crop_cuts_the_image_to_the_ratio_around_its_focus_point(): void
    {
        $post = Post::factory()->create();
        $asset = $this->attachedImage($post, ['crop_x' => 0, 'crop_y' => 0.5, 'crop_zoom' => 1]);

        $cropped = (new PublishImageCropper)->crop($asset, self::jpeg(1600, 900), 1.0, $post);

        $this->assertNotNull($cropped);
        $this->assertSame([900, 900], self::pixelSize($cropped));
    }

    public function test_zoom_tightens_the_crop(): void
    {
        $post = Post::factory()->create();
        $asset = $this->attachedImage($post, ['crop_zoom' => 2]);

        $cropped = (new PublishImageCropper)->crop($asset, self::jpeg(1000, 1000), 1.0, $post);

        $this->assertSame([500, 500], self::pixelSize((string) $cropped));
    }

    public function test_an_image_already_in_shape_is_sent_untouched(): void
    {
        $post = Post::factory()->create();
        $asset = $this->attachedImage($post);

        $this->assertNull((new PublishImageCropper)->crop($asset, self::jpeg(800, 1000), 0.8, $post));
    }

    public function test_unreadable_bytes_and_gifs_fall_back_to_the_original(): void
    {
        $post = Post::factory()->create();
        $asset = $this->attachedImage($post);

        $this->assertNull((new PublishImageCropper)->crop($asset, 'not-an-image', 1.0, $post));

        $asset->mime_type = 'image/gif';
        $this->assertNull((new PublishImageCropper)->crop($asset, self::jpeg(1600, 900), 1.0, $post));
    }

    public function test_framing_defaults_to_centred_without_a_pivot(): void
    {
        $this->assertSame([0.5, 0.5, 1.0], PublishImageCropper::framing(new Asset));
    }
}
