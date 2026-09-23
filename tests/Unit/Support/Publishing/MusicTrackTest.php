<?php

namespace Tests\Unit\Support\Publishing;

use App\Enums\Platform;
use App\Support\Publishing\MusicTrack;
use Tests\TestCase;

class MusicTrackTest extends TestCase
{
    public function test_it_exposes_the_expected_readonly_fields(): void
    {
        $track = new MusicTrack(
            id: 'track-123',
            name: 'Some Song',
            artist: 'Some Artist',
            previewUrl: 'https://example.com/preview.mp3',
            platform: Platform::Instagram,
        );

        $this->assertSame('track-123', $track->id);
        $this->assertSame('Some Song', $track->name);
        $this->assertSame('Some Artist', $track->artist);
        $this->assertSame('https://example.com/preview.mp3', $track->previewUrl);
        $this->assertSame(Platform::Instagram, $track->platform);
    }

    public function test_artist_and_preview_url_are_nullable(): void
    {
        $track = new MusicTrack(
            id: 'track-123',
            name: 'Some Song',
            artist: null,
            previewUrl: null,
            platform: Platform::TikTok,
        );

        $this->assertNull($track->artist);
        $this->assertNull($track->previewUrl);
    }
}
