<?php

namespace App\Services\Media;

use App\Contracts\MediaProber;
use App\Support\Media\VideoProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Step I-3.5 / M-2.4 — `MediaProber` on the ffprobe / ffmpeg binaries (`assets.ffprobe_path`,
 * `assets.ffmpeg_path`). When a binary is missing or fails, the probe returns null and the asset
 * simply stays unprobed: the checklist then skips the length/shape checks it can't make rather
 * than blocking the post.
 */
class FfprobeMediaProber implements MediaProber
{
    public function probe(string $localPath): ?VideoProbe
    {
        try {
            $result = Process::timeout(60)->run([
                (string) config('assets.ffprobe_path', 'ffprobe'),
                '-v', 'error',
                '-print_format', 'json',
                '-show_streams',
                '-show_format',
                $localPath,
            ]);
        } catch (Throwable $e) {
            Log::warning('ffprobe could not run', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('ffprobe failed', ['exit_code' => $result->exitCode(), 'error' => mb_substr($result->errorOutput(), 0, 500)]);

            return null;
        }

        $data = json_decode($result->output(), true);

        if (! is_array($data)) {
            return null;
        }

        $video = null;

        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;

                break;
            }
        }

        if ($video === null) {
            return null;
        }

        $width = isset($video['width']) ? (int) $video['width'] : null;
        $height = isset($video['height']) ? (int) $video['height'] : null;

        // A phone video shot upright is stored landscape with a rotation tag.
        $rotation = (int) ($video['tags']['rotate'] ?? 0);

        foreach ((array) ($video['side_data_list'] ?? []) as $side) {
            if (is_array($side) && isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }

        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $data['format']['duration'] ?? $video['duration'] ?? null;

        return new VideoProbe(
            width: $width,
            height: $height,
            durationMs: is_numeric($duration) ? (int) round((float) $duration * 1000) : null,
            codec: isset($video['codec_name']) ? (string) $video['codec_name'] : null,
        );
    }

    public function frame(string $localPath, int $atMs = 1000): ?string
    {
        $out = tempnam(sys_get_temp_dir(), 'frame').'.jpg';

        try {
            $result = Process::timeout(60)->run([
                (string) config('assets.ffmpeg_path', 'ffmpeg'),
                '-v', 'error',
                '-ss', sprintf('%.3f', $atMs / 1000),
                '-i', $localPath,
                '-frames:v', '1',
                '-q:v', '3',
                '-y', $out,
            ]);

            if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
                return null;
            }

            return (string) file_get_contents($out);
        } catch (Throwable $e) {
            Log::warning('ffmpeg frame grab failed', ['error' => $e->getMessage()]);

            return null;
        } finally {
            if (is_file($out)) {
                @unlink($out);
            }
        }
    }
}
