<?php

namespace App\Actions\Publishing;

use App\Contracts\MusicProvider;
use App\Enums\Platform;
use App\Support\Publishing\MusicTrack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.8.3 — the thin seam a controller calls to search the music/sound catalog for a given
 * platform. Delegates entirely to App\Contracts\MusicProvider::search() (bound to
 * UploadPostMusicProvider as of Step 1.8.2) so the controller never talks to the vendor directly.
 * A single read call against the provider, so no DB transaction is needed here.
 */
class SearchMusicAction
{
    public function __construct(private readonly MusicProvider $provider) {}

    /**
     * @return Collection<int, MusicTrack>
     */
    public function __invoke(string $query, Platform $platform): Collection
    {
        Log::info('SearchMusicAction: searching music provider', [
            'platform' => $platform->value,
        ]);

        $results = $this->provider->search($query, $platform);

        Log::info('SearchMusicAction: music provider search completed', [
            'platform' => $platform->value,
            'count' => $results->count(),
        ]);

        return $results;
    }
}
