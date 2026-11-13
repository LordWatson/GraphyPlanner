<?php

namespace App\Support\Stories;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step S-4.2 — the file a handoff page shares, ready to send: an image as bytes (it has just been
 * cropped in memory), a video as a read stream (it can be 100 MB and must not be).
 *
 * A video on a seekable stream (a local disk) answers a single `Range` request, because a phone's
 * own player will not start without one. A stream that cannot seek (a remote disk) is always sent
 * whole with `Accept-Ranges: none`: reaching a late range would mean reading the file from its
 * first byte on every request, which an unauthenticated route must not be made to do.
 */
final class StoryHandoffMedia
{
    private const CHUNK = 65536;

    /**
     * @param  resource|null  $stream
     */
    public function __construct(
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly ?string $bytes = null,
        public readonly mixed $stream = null,
        public readonly ?int $size = null,
    ) {}

    /**
     * `inline`, so the page can show it and the share sheet can take it; `private, no-store`,
     * because the URL carries the handoff token and nothing between us and the phone should keep
     * a copy.
     */
    public function toResponse(?string $range = null): Response
    {
        $headers = [
            'Content-Type' => $this->mimeType,
            'Content-Disposition' => 'inline; filename="'.$this->filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($this->bytes !== null) {
            return new Response($this->bytes, 200, [...$headers, 'Content-Length' => (string) strlen($this->bytes)]);
        }

        $stream = $this->stream;
        $size = $this->size;

        // Without a known length there is nothing to measure a range against.
        if ($size === null || $size <= 0) {
            return new StreamedResponse(fn () => self::send($stream, 0, null), 200, [...$headers, 'Accept-Ranges' => 'none']);
        }

        // A stream that cannot seek (a remote disk) would have to be read from its first byte
        // to reach a late range, on every request. It gets the whole file instead, and says so.
        if (! is_resource($stream) || ! stream_get_meta_data($stream)['seekable']) {
            return new StreamedResponse(fn () => self::send($stream, 0, $size), 200, [
                ...$headers,
                'Content-Length' => (string) $size,
                'Accept-Ranges' => 'none',
            ]);
        }

        $headers['Accept-Ranges'] = 'bytes';
        $span = self::parseRange($range, $size);

        if ($span === false) {
            fclose($stream);

            return new Response('', 416, [...$headers, 'Content-Range' => "bytes */{$size}"]);
        }

        if ($span === null) {
            return new StreamedResponse(fn () => self::send($stream, 0, $size), 200, [...$headers, 'Content-Length' => (string) $size]);
        }

        [$start, $end] = $span;
        $length = $end - $start + 1;

        return new StreamedResponse(fn () => self::send($stream, $start, $length), 206, [
            ...$headers,
            'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
        ]);
    }

    /**
     * One `bytes=start-end` range against a file of `$size` bytes: `[start, end]`, null when no
     * (usable) range was asked for, false when it cannot be satisfied.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parseRange(?string $range, int $size): array|false|null
    {
        if ($range === null || preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) !== 1) {
            return null;
        }

        [, $from, $to] = $matches;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // A suffix range: the last N bytes.
            $start = max(0, $size - (int) $to);
            $end = $size - 1;
        } else {
            $start = (int) $from;
            $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);
        }

        return $start >= $size || $start > $end ? false : [$start, $end];
    }

    /**
     * @param  resource|null  $stream
     */
    private static function send(mixed $stream, int $start, ?int $length): void
    {
        if (! is_resource($stream)) {
            return;
        }

        if ($start > 0 && fseek($stream, $start) !== 0) {
            fclose($stream);

            return;
        }

        $remaining = $length;

        while (! feof($stream) && ($remaining === null || $remaining > 0)) {
            $chunk = fread($stream, $remaining === null ? self::CHUNK : max(1, min(self::CHUNK, $remaining)));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            flush();

            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
        }

        fclose($stream);
    }
}
