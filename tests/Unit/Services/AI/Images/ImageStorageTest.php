<?php

namespace Tests\Unit\Services\AI\Images;

use App\Services\AI\Images\ImageNormalizer;
use App\Services\LocalAssetStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step 16.2: AssetStorage::storeContents() and the ImageNormalizer.
 */
class ImageStorageTest extends TestCase
{
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_store_contents_writes_under_the_directory_and_returns_the_store_shape(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $stored = (new LocalAssetStorage)->storeContents('bytes', 'image/png', 'ai-images/1/2', 'png');

        $this->assertSame('local', $stored['disk']);
        $this->assertMatchesRegularExpression('#^ai-images/1/2/[0-9a-f-]{36}\.png$#', $stored['path']);
        $this->assertSame('image/png', $stored['mime_type']);
        $this->assertSame(5, $stored['size']);
        $this->assertNotEmpty($stored['url']);
        Storage::disk('local')->assertExists($stored['path']);
        $this->assertSame('bytes', (new LocalAssetStorage)->contents('local', $stored['path']));
        $this->assertNull((new LocalAssetStorage)->contents('local', 'missing.png'));
    }

    public function test_reference_normalisation_caps_the_long_edge_and_encodes_webp(): void
    {
        $normalized = (new ImageNormalizer)->reference($this->png(2400, 1200));

        $this->assertSame('image/webp', $normalized['mime_type']);
        $this->assertSame(1024, $normalized['width']);
        $this->assertSame(512, $normalized['height']);
        $this->assertSame('image/webp', getimagesizefromstring($normalized['bytes'])['mime']);
    }

    public function test_small_images_are_never_upscaled(): void
    {
        $normalized = (new ImageNormalizer)->preview($this->png(300, 400));

        $this->assertSame([300, 400], [$normalized['width'], $normalized['height']]);
    }

    public function test_dimensions_reads_encoded_bytes(): void
    {
        $this->assertSame([12, 34], (new ImageNormalizer)->dimensions($this->png(12, 34)));
        $this->assertSame([0, 0], (new ImageNormalizer)->dimensions('not an image'));
    }
}
