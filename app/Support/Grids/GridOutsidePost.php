<?php

namespace App\Support\Grids;

use Carbon\CarbonInterface;

/**
 * Step 18.11 — a feed post on the plan's Instagram account that isn't part of the plan but goes
 * out between (or after) the plan's dates, so it lands in the grid and shifts the tiles.
 */
final readonly class GridOutsidePost
{
    public function __construct(
        public int $postId,
        public CarbonInterface $publishAt,
    ) {}
}
