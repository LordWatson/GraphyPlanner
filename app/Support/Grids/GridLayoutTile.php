<?php

namespace App\Support\Grids;

use Carbon\CarbonInterface;

/**
 * Step 18.2 — one plan tile as the layout core sees it: its display position (1 = top-left =
 * newest), its split group, and when it goes out (UTC, when known).
 */
final readonly class GridLayoutTile
{
    public function __construct(
        public int $id,
        public int $position,
        public ?string $splitGroupId = null,
        public ?CarbonInterface $publishAt = null,
    ) {}
}
