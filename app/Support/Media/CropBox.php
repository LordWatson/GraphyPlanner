<?php

namespace App\Support\Media;

/**
 * The pixel rectangle a fixed-ratio crop takes from an image: the largest box of that ratio,
 * shrunk by `zoom`, centred on the focus point and clamped inside the image. Pure maths so the
 * publisher and the editor's preview (`cropBox()` in `resources/js/lib/media-format.ts`, same
 * algorithm on fractions) always agree on what gets cut.
 */
final readonly class CropBox
{
    public const MIN_ZOOM = 1.0;

    public const MAX_ZOOM = 3.0;

    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    /**
     * @param  float  $ratio  target width ÷ height
     * @param  float  $focusX  0 (left) … 1 (right)
     * @param  float  $focusY  0 (top) … 1 (bottom)
     */
    public static function compute(
        int $imageWidth,
        int $imageHeight,
        float $ratio,
        float $focusX = 0.5,
        float $focusY = 0.5,
        float $zoom = 1.0,
    ): self {
        $imageWidth = max(1, $imageWidth);
        $imageHeight = max(1, $imageHeight);
        $zoom = min(self::MAX_ZOOM, max(self::MIN_ZOOM, $zoom));

        // Widest box of the target ratio that fits, in pixels.
        $width = (float) $imageWidth;
        $height = $width / $ratio;

        if ($height > $imageHeight) {
            $height = (float) $imageHeight;
            $width = $height * $ratio;
        }

        $width = max(1, (int) round($width / $zoom));
        $height = max(1, (int) round($height / $zoom));

        $x = (int) round(self::clamp($focusX) * $imageWidth - $width / 2);
        $y = (int) round(self::clamp($focusY) * $imageHeight - $height / 2);

        return new self(
            x: min(max(0, $x), $imageWidth - $width),
            y: min(max(0, $y), $imageHeight - $height),
            width: $width,
            height: $height,
        );
    }

    /**
     * Whether the box is the whole image (nothing to cut).
     */
    public function coversWhole(int $imageWidth, int $imageHeight): bool
    {
        return $this->x === 0 && $this->y === 0 && $this->width === $imageWidth && $this->height === $imageHeight;
    }

    private static function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
