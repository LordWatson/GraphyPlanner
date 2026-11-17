<?php

namespace Tests\Feature\Stories;

use App\Actions\Stories\GetStoryHandoffMediaAction;
use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Post;
use App\Support\Stories\StoryHandoffMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\TestCase;

/**
 * Step S-4.2 — `GET story/{token}/media` serves exactly the file Instagram should receive: the
 * item the adapter would send, an image cut to 9:16 the way the auto lane cuts it, a video
 * streamed as uploaded (with `Range` support).
 */
class StoryHandoffMediaTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();
        Notification::fake();
        Storage::fake('local');
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 32, 85, 87));

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** 1,000 bytes in which every position is recognisable. */
    private static function videoBytes(): string
    {
        $bytes = '';

        for ($i = 0; $i < 250; $i++) {
            $bytes .= sprintf('%04d', $i);
        }

        return $bytes;
    }

    /**
     * Store `$bytes` on `$disk` and attach an asset pointing at it to the post.
     */
    private function attach(Post $post, string $bytes, AssetType $type = AssetType::Image, string $disk = 'local', int $sortOrder = 0, bool $store = true): Asset
    {
        $extension = $type === AssetType::Video ? 'mp4' : 'jpg';
        $path = 'assets/'.Str::uuid().'.'.$extension;

        if ($store) {
            Storage::disk($disk)->put($path, $bytes);
        }

        $asset = Asset::factory()->create([
            'client_id' => $post->client_id,
            'source' => AssetSource::Upload,
            'type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => 'agency-internal-name.'.$extension,
            'mime_type' => $type === AssetType::Video ? 'video/mp4' : 'image/jpeg',
            'size' => strlen($bytes),
        ]);

        $post->assets()->attach($asset, ['sort_order' => $sortOrder]);

        return $asset;
    }

    /**
     * A Story out for posting by hand, and the URL of its media.
     *
     * @return array{0: Post, 1: string}
     */
    private function readyStory(): array
    {
        $post = $this->startPublishing($this->storyPost());

        return [$post, route('story-handoff.media', ['token' => $this->issueToken($this->openHandoff($post))])];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array{0: int, 1: int}
     */
    private static function pixelSize(TestResponse $response): array
    {
        $size = getimagesizefromstring((string) $response->getContent());

        self::assertIsArray($size, 'The response is not an image.');

        return [$size[0], $size[1]];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertNineBySixteen(TestResponse $response): void
    {
        [$width, $height] = self::pixelSize($response);

        // Within a pixel of 9:16.
        $this->assertLessThanOrEqual(1, abs($width - $height * 9 / 16), "{$width}x{$height} is not 9:16.");
    }

    public function test_an_image_is_served_cropped_to_nine_by_sixteen(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::jpeg(1200, 1200));

        $response = $this->get($url)->assertOk();

        $this->assertNineBySixteen($response);
        $this->assertSame([675, 1200], self::pixelSize($response));
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('Content-Disposition', 'inline; filename="alima-story.jpg"');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Content-Length', (string) strlen((string) $response->getContent()));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_wide_image_is_cropped_to_nine_by_sixteen_too(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::jpeg(1600, 900));

        $response = $this->get($url)->assertOk();

        $this->assertNineBySixteen($response);
        $this->assertSame(900, self::pixelSize($response)[1]);
    }

    public function test_an_image_already_nine_by_sixteen_is_served_unchanged(): void
    {
        [$post, $url] = $this->readyStory();
        $bytes = self::jpeg(1080, 1920);
        $this->attach($post, $bytes);

        $response = $this->get($url)->assertOk();

        $this->assertSame($bytes, $response->getContent());
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_video_streams_as_uploaded(): void
    {
        [$post, $url] = $this->readyStory();
        $bytes = self::videoBytes();
        $this->attach($post, $bytes, AssetType::Video);

        $response = $this->get($url)->assertOk();

        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Content-Disposition', 'inline; filename="alima-story.mp4"');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Length', '1000');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_a_video_answers_a_range_request_with_exactly_those_bytes(): void
    {
        [$post, $url] = $this->readyStory();
        $bytes = self::videoBytes();
        $this->attach($post, $bytes, AssetType::Video);

        $first = $this->get($url, ['Range' => 'bytes=0-99']);

        $first->assertStatus(206);
        $first->assertHeader('Content-Range', 'bytes 0-99/1000');
        $first->assertHeader('Content-Length', '100');
        $first->assertHeader('Accept-Ranges', 'bytes');
        $first->assertHeader('Content-Type', 'video/mp4');
        $content = $first->streamedContent();
        $this->assertSame(100, strlen($content));
        $this->assertSame(substr($bytes, 0, 100), $content);

        $middle = $this->get($url, ['Range' => 'bytes=500-509']);
        $middle->assertStatus(206)->assertHeader('Content-Range', 'bytes 500-509/1000');
        $this->assertSame(substr($bytes, 500, 10), $middle->streamedContent());

        $tail = $this->get($url, ['Range' => 'bytes=-10']);
        $tail->assertStatus(206)->assertHeader('Content-Range', 'bytes 990-999/1000');
        $this->assertSame(substr($bytes, -10), $tail->streamedContent());
    }

    public function test_an_unsatisfiable_range_is_a_416(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::videoBytes(), AssetType::Video);

        $response = $this->get($url, ['Range' => 'bytes=5000-6000']);

        $response->assertStatus(416);
        $response->assertHeader('Content-Range', 'bytes */1000');
        $this->assertSame('', (string) $response->getContent());
    }

    public function test_only_the_first_of_several_images_is_served(): void
    {
        [$post, $url] = $this->readyStory();
        // Attached out of order: the sort order decides, as it does for the adapter.
        $this->attach($post, self::jpeg(800, 800), sortOrder: 1);
        $first = $this->attach($post, self::jpeg(1200, 1200), sortOrder: 0);
        $this->attach($post, self::jpeg(400, 400), sortOrder: 2);

        $this->assertSame($first->id, $post->refresh()->publishableMedia()->first()?->id);

        $response = $this->get($url)->assertOk();

        $this->assertSame([675, 1200], self::pixelSize($response));
    }

    public function test_a_video_is_served_ahead_of_the_images_next_to_it(): void
    {
        [$post, $url] = $this->readyStory();
        $bytes = self::videoBytes();
        $this->attach($post, self::jpeg(1200, 1200), sortOrder: 0);
        $this->attach($post, $bytes, AssetType::Video, sortOrder: 1);

        $response = $this->get($url)->assertOk();

        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Content-Disposition', 'inline; filename="alima-story.mp4"');
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_a_wrong_token_gets_nothing(): void
    {
        [$post] = $this->readyStory();
        $this->attach($post, self::jpeg(1200, 1200));

        $this->get(route('story-handoff.media', ['token' => Str::random(64)]))->assertNotFound();
    }

    public function test_a_post_with_no_media_is_a_404(): void
    {
        [, $url] = $this->readyStory();

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_image_file_is_a_404(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::jpeg(1200, 1200), store: false);

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_video_file_is_a_404(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::videoBytes(), AssetType::Video, store: false);

        $this->get($url)->assertNotFound();
    }

    public function test_a_video_on_a_remote_disk_still_streams(): void
    {
        // The fake `s3` disk is a local folder, so its stream can seek and ranges are answered. A
        // real remote stream cannot: see the "cannot seek" tests below.
        Storage::fake('s3');
        [$post, $url] = $this->readyStory();
        $bytes = self::videoBytes();
        $this->attach($post, $bytes, AssetType::Video, disk: 's3');
        Storage::disk('local')->assertDirectoryEmpty('assets');

        $response = $this->get($url)->assertOk();
        $response->assertHeader('Content-Type', 'video/mp4');
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame($bytes, $response->streamedContent());

        $range = $this->get($url, ['Range' => 'bytes=100-199']);
        $range->assertStatus(206)->assertHeader('Content-Range', 'bytes 100-199/1000');
        $this->assertSame(substr($bytes, 100, 100), $range->streamedContent());
    }

    public function test_an_image_on_a_remote_disk_is_still_cropped_and_served(): void
    {
        Storage::fake('s3');
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::jpeg(1200, 1200), disk: 's3');

        $response = $this->get($url)->assertOk();

        $this->assertNineBySixteen($response);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    // --- Only safe types are ever served -------------------------------------------------------

    public function test_an_asset_declared_as_svg_or_html_is_never_served_and_the_page_offers_no_media(): void
    {
        $this->withoutVite();

        foreach (['image/svg+xml', 'text/html', ' IMAGE/SVG+XML ', 'application/xhtml+xml', 'application/octet-stream'] as $mime) {
            $post = $this->startPublishing($this->storyPost());
            $token = $this->issueToken($this->openHandoff($post));
            $asset = $this->attach($post, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
            $asset->update(['mime_type' => $mime]);

            $this->get(route('story-handoff.media', ['token' => $token]))->assertNotFound();

            $this->get(route('story-handoff.show', ['token' => $token]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('story-handoff/show', false)
                    ->where('handoff.state', 'ready')
                    ->where('handoff.media', null));
        }
    }

    public function test_a_video_asset_with_an_unservable_type_is_a_404_too(): void
    {
        [$post, $url] = $this->readyStory();
        $this->attach($post, self::videoBytes(), AssetType::Video)->update(['mime_type' => 'text/html']);

        $this->get($url)->assertNotFound();
    }

    public function test_the_stored_type_is_trimmed_and_lower_cased_before_it_is_served(): void
    {
        $this->withoutVite();
        [$post, $url] = $this->readyStory();
        // Already 9:16, so it is served as stored and only the declared type is in play.
        $image = imagecreatetruecolor(90, 160);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $this->attach($post, $bytes)->update(['mime_type' => '  IMAGE/PNG ']);

        $response = $this->get($url)->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'inline; filename="alima-story.png"');
        $this->assertSame($bytes, $response->getContent());

        $this->get(str_replace('/media', '', $url))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('story-handoff/show', false)
                ->where('handoff.media.mime_type', 'image/png')
                ->where('handoff.media.filename', 'alima-story.png'));
    }

    public function test_every_servable_type_is_an_image_or_a_video_and_none_can_run(): void
    {
        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'],
            GetStoryHandoffMediaAction::SERVABLE_TYPES,
        );

        $svg = (new Asset)->forceFill(['type' => AssetType::Image, 'mime_type' => 'image/svg+xml']);
        $untyped = (new Asset)->forceFill(['type' => AssetType::Video, 'mime_type' => null]);

        $this->assertFalse(GetStoryHandoffMediaAction::isServable($svg));
        $this->assertTrue(GetStoryHandoffMediaAction::isServable($untyped));
        $this->assertSame('video/mp4', GetStoryHandoffMediaAction::mimeFor($untyped));
    }

    // --- A stream that cannot seek (a remote disk) ---------------------------------------------

    /**
     * A read stream that cannot seek, holding `$bytes` — what a remote disk hands back. (The fake
     * `s3` disk is a local folder, so its streams *can* seek.)
     *
     * @return resource
     */
    private static function nonSeekableStream(string $bytes)
    {
        $pair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        fwrite($pair[0], $bytes);
        fclose($pair[0]);

        self::assertFalse(stream_get_meta_data($pair[1])['seekable']);

        return $pair[1];
    }

    private static function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_a_stream_that_cannot_seek_answers_a_range_request_with_the_whole_file(): void
    {
        $bytes = self::videoBytes();
        $media = new StoryHandoffMedia('video/mp4', 'alima-story.mp4', stream: self::nonSeekableStream($bytes), size: strlen($bytes));

        $response = $media->toResponse('bytes=10-19');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('none', $response->headers->get('Accept-Ranges'));
        $this->assertSame('1000', $response->headers->get('Content-Length'));
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame($bytes, self::streamed($response));
    }

    public function test_a_stream_that_cannot_seek_ignores_a_range_it_could_never_satisfy(): void
    {
        $bytes = self::videoBytes();
        $media = new StoryHandoffMedia('video/mp4', 'alima-story.mp4', stream: self::nonSeekableStream($bytes), size: strlen($bytes));

        // On a seekable stream this is a 416; here the range is not read at all.
        $response = $media->toResponse('bytes=5000-6000');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('none', $response->headers->get('Accept-Ranges'));
        $this->assertSame($bytes, self::streamed($response));
    }

    public function test_a_stream_of_unknown_length_is_sent_whole_without_ranges(): void
    {
        $bytes = self::videoBytes();
        $media = new StoryHandoffMedia('video/mp4', 'alima-story.mp4', stream: self::nonSeekableStream($bytes), size: null);

        $response = $media->toResponse('bytes=10-19');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('none', $response->headers->get('Accept-Ranges'));
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame($bytes, self::streamed($response));
    }

    public function test_the_media_route_sends_a_video_whole_when_its_disk_cannot_seek(): void
    {
        [$post, $url] = $this->readyStory();
        $bytes = self::videoBytes();
        $asset = $this->attach($post, $bytes, AssetType::Video);

        $storage = Mockery::mock(AssetStorage::class);
        $storage->shouldReceive('readStream')
            ->once()
            ->with('local', $asset->path)
            ->andReturnUsing(fn () => self::nonSeekableStream($bytes));
        $this->app->instance(AssetStorage::class, $storage);

        $response = $this->get($url, ['Range' => 'bytes=100-199']);

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'none');
        $response->assertHeader('Content-Length', '1000');
        $response->assertHeaderMissing('Content-Range');
        $response->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_parse_range_reads_a_closed_range(): void
    {
        $this->assertSame([0, 99], StoryHandoffMedia::parseRange('bytes=0-99', 1000));
        $this->assertSame([0, 0], StoryHandoffMedia::parseRange('bytes=0-0', 1000));
        $this->assertSame([999, 999], StoryHandoffMedia::parseRange('bytes=999-999', 1000));
        $this->assertSame([10, 20], StoryHandoffMedia::parseRange('  bytes=10-20 ', 1000));
    }

    public function test_parse_range_reads_an_open_ended_range_to_the_last_byte(): void
    {
        $this->assertSame([500, 999], StoryHandoffMedia::parseRange('bytes=500-', 1000));
        $this->assertSame([0, 999], StoryHandoffMedia::parseRange('bytes=0-', 1000));
    }

    public function test_parse_range_reads_a_suffix_range_as_the_last_bytes(): void
    {
        $this->assertSame([900, 999], StoryHandoffMedia::parseRange('bytes=-100', 1000));
        // More than the file holds: all of it.
        $this->assertSame([0, 999], StoryHandoffMedia::parseRange('bytes=-5000', 1000));
    }

    public function test_parse_range_clamps_an_end_beyond_the_size(): void
    {
        $this->assertSame([0, 999], StoryHandoffMedia::parseRange('bytes=0-5000', 1000));
        $this->assertSame([990, 999], StoryHandoffMedia::parseRange('bytes=990-1000', 1000));
    }

    public function test_parse_range_refuses_a_range_that_cannot_be_satisfied(): void
    {
        $this->assertFalse(StoryHandoffMedia::parseRange('bytes=1000-', 1000));
        $this->assertFalse(StoryHandoffMedia::parseRange('bytes=2000-3000', 1000));
        $this->assertFalse(StoryHandoffMedia::parseRange('bytes=50-10', 1000));
        $this->assertFalse(StoryHandoffMedia::parseRange('bytes=-0', 1000));
    }

    public function test_parse_range_ignores_garbage(): void
    {
        $this->assertNull(StoryHandoffMedia::parseRange(null, 1000));
        $this->assertNull(StoryHandoffMedia::parseRange('', 1000));
        $this->assertNull(StoryHandoffMedia::parseRange('garbage', 1000));
        $this->assertNull(StoryHandoffMedia::parseRange('bytes=-', 1000));
        $this->assertNull(StoryHandoffMedia::parseRange('bytes=a-b', 1000));
        $this->assertNull(StoryHandoffMedia::parseRange('items=0-99', 1000));
        // Several ranges at once are not supported: the whole file is sent instead.
        $this->assertNull(StoryHandoffMedia::parseRange('bytes=0-1,5-6', 1000));
    }
}
