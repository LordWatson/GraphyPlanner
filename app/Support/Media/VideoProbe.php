<?php

namespace App\Support\Media;

/**
 * What `MediaProber::probe()` learned about a video. Width and height are as displayed (rotation
 * applied).
 */
final readonly class VideoProbe
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?int $durationMs,
        public ?string $codec,
    ) {}
}
