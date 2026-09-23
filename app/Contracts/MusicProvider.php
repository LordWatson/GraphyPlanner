<?php

namespace App\Contracts;

use App\Enums\Platform;
use App\Support\Publishing\MusicTrack;
use Illuminate\Support\Collection;

/**
 * Contract for a music/sound-library lookup vendor (Step 1.8), kept swappable
 * the same way App\Contracts\PublishAdapter is — an interface first, bound in
 * a Service Provider — so the vendor can be mocked in tests and, later, swapped
 * without touching SearchMusicAction or the editor's music picker.
 */
interface MusicProvider
{
    /**
     * Search the vendor's sound/track catalog for the given platform.
     *
     * @return Collection<int, MusicTrack>
     */
    public function search(string $query, Platform $platform): Collection;

    /**
     * Look up a single track by its vendor id for the given platform. Returns
     * null when the vendor has no track matching that id.
     */
    public function find(string $id, Platform $platform): ?MusicTrack;
}
