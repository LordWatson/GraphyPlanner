<?php

namespace App\Services\AI\Images;

use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resizes/encodes images for the Image Studio (Step 16.2), GD driver only (no system binaries):
 * Brand Brain references are normalised once (long edge ≤1024px, WebP) and reused for every
 * call, and each generated image gets a light WebP preview for the studio grid while the asset
 * keeps the vendor original (its embedded provenance metadata survives untouched).
 */
class ImageNormalizer
{
    public const REFERENCE_LONG_EDGE = 1024;

    public const PREVIEW_LONG_EDGE = 640;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * Downscale (never upscale) so the long edge is at most $longEdge and encode as WebP.
     *
     * @return array{bytes: string, mime_type: string, width: int, height: int}
     */
    public function toWebp(string $bytes, int $longEdge = self::REFERENCE_LONG_EDGE, int $quality = 82): array
    {
        $image = $this->manager->read($bytes);
        $image->scaleDown($longEdge, $longEdge);

        return [
            'bytes' => (string) $image->toWebp($quality),
            'mime_type' => 'image/webp',
            'width' => $image->width(),
            'height' => $image->height(),
        ];
    }

    /**
     * @return array{bytes: string, mime_type: string, width: int, height: int}
     */
    public function reference(string $bytes): array
    {
        return $this->toWebp($bytes, self::REFERENCE_LONG_EDGE);
    }

    /**
     * @return array{bytes: string, mime_type: string, width: int, height: int}
     */
    public function preview(string $bytes): array
    {
        return $this->toWebp($bytes, self::PREVIEW_LONG_EDGE, 78);
    }

    /**
     * Pixel dimensions of encoded image bytes, or [0, 0] when they can't be read.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $bytes): array
    {
        $size = @getimagesizefromstring($bytes);

        if ($size !== false) {
            return [(int) $size[0], (int) $size[1]];
        }

        try {
            $image = $this->manager->read($bytes);

            return [$image->width(), $image->height()];
        } catch (Throwable) {
            return [0, 0];
        }
    }

    /**
     * A solid-colour PNG placeholder of exact dimensions (NullImageProvider).
     */
    public function solidPng(int $width, int $height, string $hex): string
    {
        return (string) $this->manager->create($width, $height)->fill($hex)->toPng();
    }
}
