<?php

namespace App\Contracts;

use App\Support\Media\VideoProbe;

/**
 * Step I-3.5 / M-2.4 — reads what a video file is (size, length, codec) and grabs a frame for its
 * thumbnail. Bound to `FfprobeMediaProber` (ffprobe/ffmpeg binaries); swappable and faked in
 * tests. Implementations never throw: they return null when they can't tell.
 */
interface MediaProber
{
    /**
     * Probe a video file on the local filesystem.
     */
    public function probe(string $localPath): ?VideoProbe;

    /**
     * Encoded image bytes (JPEG) of the frame at `$atMs`, or null when no frame could be read.
     */
    public function frame(string $localPath, int $atMs = 1000): ?string;
}
