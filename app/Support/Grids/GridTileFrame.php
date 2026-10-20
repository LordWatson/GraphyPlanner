<?php

namespace App\Support\Grids;

use App\Enums\MediaRatio;
use App\Enums\Platform;

/**
 * Step 18.2 — what a tile shows on the Instagram profile grid. The tile's first image is framed
 * exactly the way the publisher cuts it (`CropBox` at the tile's format, the same maths as
 * `PublishImageCropper`), then Instagram centre-crops that frame to the 3:4 grid tile.
 *
 * Works on fractions of the image (0–1), like `cropBox()` in `resources/js/lib/media-format.ts`,
 * and is mirrored by `gridTileFrame()` in `resources/js/lib/grid-layout.ts`. Both are checked
 * against the shared fixture `tests/Fixtures/grid-layout.json` so they can't drift.
 */
final readonly class GridTileFrame
{
    /** The profile grid's tile shape (3:4 portrait, since early 2025). */
    public const GRID_RATIO = 3 / 4;

    /** Below this share of the frame hidden, the grid shows the post as published. */
    private const HIDDEN_TOLERANCE = 0.005;

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $frame  the published frame
     * @param  array{x: float, y: float, w: float, h: float}  $grid  the part the grid shows
     */
    public function __construct(
        public array $frame,
        public array $grid,
        public bool $croppedOnGrid,
        public float $hiddenShare,
    ) {}

    /**
     * @param  int|null  $width  the image's pixel width (null when unknown — treated as already
     *                           having the format's shape)
     * @param  float  $focusX  0 (left) … 1 (right)
     * @param  float  $focusY  0 (top) … 1 (bottom)
     */
    public static function compute(
        ?int $width,
        ?int $height,
        MediaRatio $format,
        float $focusX = 0.5,
        float $focusY = 0.5,
        float $zoom = 1.0,
    ): self {
        $aspect = $width !== null && $height !== null && $width > 0 && $height > 0
            ? $width / $height
            : ($format->quotient() ?? 1.0);

        $ratio = $format->quotient() ?? self::originalRatio($aspect);
        $frame = self::cropBox($aspect, $ratio, $focusX, $focusY, $zoom);

        // The frame's real shape in pixels, then the centred 3:4 window the grid shows of it.
        $frameAspect = ($frame['w'] * $aspect) / $frame['h'];
        $grid = $frame;

        if ($frameAspect > self::GRID_RATIO) {
            $grid['w'] = $frame['w'] * (self::GRID_RATIO / $frameAspect);
            $grid['x'] = $frame['x'] + ($frame['w'] - $grid['w']) / 2;
        } elseif ($frameAspect < self::GRID_RATIO) {
            $grid['h'] = $frame['h'] * ($frameAspect / self::GRID_RATIO);
            $grid['y'] = $frame['y'] + ($frame['h'] - $grid['h']) / 2;
        }

        $hidden = max(0.0, 1 - ($grid['w'] * $grid['h']) / ($frame['w'] * $frame['h']));

        return new self(
            frame: self::rounded($frame),
            grid: self::rounded($grid),
            croppedOnGrid: $hidden > self::HIDDEN_TOLERANCE,
            hiddenShare: round($hidden, 4),
        );
    }

    /**
     * `Original` on Instagram: the image keeps its shape, clamped into the feed's accepted range
     * (the registry's `aspect` for the feed format), as `PublishImageCropper::targetRatio()` does.
     */
    public static function originalRatio(float $aspect): float
    {
        [$min, $max] = Platform::Instagram->spec()->format('feed')->aspect ?? [0.8, 1.91];

        return min($max, max($min, $aspect));
    }

    /**
     * `CropBox::compute()` on fractions — the largest box of `$ratio` inside an image of
     * `$aspect`, shrunk by zoom, centred on the focus point and clamped inside the image.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    public static function cropBox(float $aspect, float $ratio, float $focusX, float $focusY, float $zoom): array
    {
        $w = 1.0;
        $h = 1.0;

        if ($aspect > $ratio) {
            $w = $ratio / $aspect;
        } else {
            $h = $aspect / $ratio;
        }

        $zoom = min(3.0, max(1.0, $zoom));
        $w /= $zoom;
        $h /= $zoom;

        return [
            'x' => min(1 - $w, max(0.0, min(1.0, max(0.0, $focusX)) - $w / 2)),
            'y' => min(1 - $h, max(0.0, min(1.0, max(0.0, $focusY)) - $h / 2)),
            'w' => $w,
            'h' => $h,
        ];
    }

    /**
     * @return array{frame: array{x: float, y: float, w: float, h: float}, grid: array{x: float, y: float, w: float, h: float}, cropped_on_grid: bool, hidden_share: float}
     */
    public function toArray(): array
    {
        return [
            'frame' => $this->frame,
            'grid' => $this->grid,
            'cropped_on_grid' => $this->croppedOnGrid,
            'hidden_share' => $this->hiddenShare,
        ];
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function rounded(array $rect): array
    {
        return array_map(fn (float $value): float => round($value, 4), $rect);
    }
}
