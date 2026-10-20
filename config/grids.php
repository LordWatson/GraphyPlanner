<?php

/*
|--------------------------------------------------------------------------
| Instagram grid planner (Phase 18)
|--------------------------------------------------------------------------
|
| - `min_gap_minutes`: grid posts must go out in publish order at least this far apart, so the
|   vendor can't swap two close posts (`GridSequenceValidator`, Step 18.10).
| - `feed_cache_minutes` / `feed_limit`: the live profile feed (`ProfileFeedSource`, Step 18.3) is
|   cached per account and capped. `feed_refresh_per_hour`: manual "Refresh" calls per account.
| - `split_tile_min_width`: a split slices one image into 3:4 tiles at least this many pixels wide
|   (Instagram's 1080px feed width), so smaller sources are refused (Step 18.4).
| - `prune_archived_after_months`: `grids:prune` soft-deletes archived plans older than this.
|
*/

return [
    'min_gap_minutes' => (int) env('GRIDS_MIN_GAP_MINUTES', 10),
    'feed_cache_minutes' => (int) env('GRIDS_FEED_CACHE_MINUTES', 15),
    'feed_limit' => 30,
    'feed_refresh_per_hour' => (int) env('GRIDS_FEED_REFRESH_PER_HOUR', 6),
    'split_tile_min_width' => 1080,
    'prune_archived_after_months' => 12,
];
