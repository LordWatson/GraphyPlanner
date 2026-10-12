<?php

namespace Tests\Unit\Actions\Publishing;

use App\Actions\Publishing\SearchMusicAction;
use App\Contracts\MusicProvider;
use App\Enums\Platform;
use App\Support\Publishing\MusicTrack;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SearchMusicActionTest extends TestCase
{
    public function test_it_delegates_the_search_to_the_music_provider(): void
    {
        $track = new MusicTrack('track-1', 'Some Song', 'Some Artist', 'https://example.test/preview.mp3', Platform::TikTok);

        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->once())
            ->method('search')
            ->with('lofi', Platform::TikTok)
            ->willReturn(new Collection([$track]));

        $results = (new SearchMusicAction($provider))('lofi', Platform::TikTok);

        $this->assertCount(1, $results);
        $this->assertSame($track, $results->first());
    }

    public function test_it_returns_an_empty_collection_when_the_provider_finds_nothing(): void
    {
        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->once())
            ->method('search')
            ->with('lofi', Platform::Instagram)
            ->willReturn(new Collection);

        $results = (new SearchMusicAction($provider))('lofi', Platform::Instagram);

        $this->assertTrue($results->isEmpty());
    }
}
