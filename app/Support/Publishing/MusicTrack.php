<?php

namespace App\Support\Publishing;

use App\Enums\Platform;

/**
 * A single music/sound track returned by a MusicProvider (Step 1.8), mirroring
 * the shape Post::music expects so a selected track's `id` round-trips
 * unchanged into UploadPostAdapter's `audio_id`/`tiktok_music_id` mapping.
 */
final readonly class MusicTrack
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $artist,
        public ?string $previewUrl,
        public Platform $platform,
    ) {}
}
