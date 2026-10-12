<?php

namespace Tests\Unit\Contracts;

use App\Contracts\MusicProvider;
use App\Enums\Platform;
use App\Support\Publishing\MusicTrack;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MusicProviderTest extends TestCase
{
    public function test_a_concrete_implementation_satisfies_the_contract(): void
    {
        $provider = new class implements MusicProvider
        {
            public function search(string $query, Platform $platform): Collection
            {
                return new Collection([
                    new MusicTrack('track-1', $query, null, null, $platform),
                ]);
            }

            public function find(string $id, Platform $platform): ?MusicTrack
            {
                return $id === 'track-1'
                    ? new MusicTrack($id, 'Found Track', null, null, $platform)
                    : null;
            }
        };

        $this->assertInstanceOf(MusicProvider::class, $provider);

        $results = $provider->search('lofi', Platform::Instagram);
        $this->assertCount(1, $results);
        $this->assertInstanceOf(MusicTrack::class, $results->first());

        $this->assertNotNull($provider->find('track-1', Platform::TikTok));
        $this->assertNull($provider->find('missing', Platform::TikTok));
    }
}
