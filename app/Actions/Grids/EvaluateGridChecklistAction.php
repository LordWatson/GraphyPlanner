<?php

namespace App\Actions\Grids;

use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\GridTileKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Services\Grids\GridPlanService;
use App\Services\Grids\GridSequenceValidator;
use App\Support\Grids\GridLayout;
use Carbon\CarbonImmutable;

/**
 * Step 18.2 — the grid's readiness checklist, the grid counterpart of
 * `EvaluatePostChecklistAction`. Hard items block sending the grid to the client or approving it
 * (Step 18.7); soft items warn. Each item names the tiles it's about (`tile_ids`) so the planner
 * can jump to them. Runs on every save and is snapshotted on `grid_plans.checklist_snapshot`.
 */
class EvaluateGridChecklistAction
{
    public function __construct(private readonly GridPlanService $grids = new GridPlanService) {}

    /**
     * @return array{items: list<array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}>, passed: bool}
     */
    public function __invoke(GridPlan $plan, ?GridLayout $layout = null): array
    {
        $this->grids->load($plan);
        $layout ??= $this->grids->layout($plan);
        $tiles = $plan->tiles;

        $items = [
            $this->item('tiles', 'The grid has at least one tile', $tiles->isEmpty() ? ['Add a few tiles to start the grid.'] : []),
            $this->media($plan),
            $this->formats($plan),
            $this->splits($plan, $layout),
            $this->account($plan),
            $this->gridCrop($plan),
            $this->dates($plan),
            $this->outside($layout),
        ];

        return [
            'items' => $items,
            'passed' => collect($items)->every(fn (array $item) => $item['passed'] || $item['soft']),
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function media(GridPlan $plan): array
    {
        $carousel = Platform::Instagram->spec()->format('carousel');
        $problems = [];
        $ids = [];

        foreach ($plan->tiles as $tile) {
            $images = $tile->assets->filter(fn (Asset $asset) => $asset->type === AssetType::Image)->count();
            $count = $tile->assets->count();
            $name = "Tile {$tile->position}";

            $problem = match ($tile->kind) {
                GridTileKind::Placeholder => "{$name} is still a placeholder.",
                GridTileKind::Image => match (true) {
                    $images === 0 => "{$name} needs an image.",
                    $count > 1 => "{$name} has {$count} items. Make it a carousel or keep one.",
                    default => null,
                },
                GridTileKind::Carousel => $count < $carousel->minItems || $count > $carousel->maxItems
                    ? "{$name} has {$count} ".($count === 1 ? 'item' : 'items').". Carousels take {$carousel->minItems}–{$carousel->maxItems}."
                    : null,
                GridTileKind::Reel => GridPlanService::hasVideo($tile) ? null : "{$name} needs a video.",
            };

            if ($problem !== null) {
                $problems[] = $problem;
                $ids[] = $tile->id;
            }
        }

        return $this->item('media', 'Every tile has its media', $problems, tileIds: $ids);
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function formats(GridPlan $plan): array
    {
        $bad = $plan->tiles->filter(fn (GridTile $tile) => ! in_array($tile->format, GridPlanService::formatsFor($tile), true));

        return $this->item(
            'formats',
            'Each tile uses a format Instagram offers',
            array_values($bad->map(fn (GridTile $tile) => "Tile {$tile->position} can't go out as {$tile->format->value}.")->all()),
            tileIds: array_values($bad->map(fn (GridTile $tile) => $tile->id)->all()),
        );
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function splits(GridPlan $plan, GridLayout $layout): array
    {
        $problems = [];
        $ids = [];
        $positions = $plan->tiles->pluck('position', 'id');

        foreach ($layout->misalignedSplits() as $group) {
            $first = (int) collect($group['tile_ids'])->map(fn (int $id) => (int) $positions->get($id))->min();
            $size = count($group['tile_ids']);

            $problems[] = match (true) {
                $size % 3 !== 0 => "The split starting at tile {$first} has {$size} tiles. A split fills whole rows of 3.",
                $group['offset'] === 0 => "Something sits inside the split starting at tile {$first}. Keep its tiles together.",
                default => "This split won't line up. Add or remove one tile above it. (Starting at tile {$first}: remove {$group['offset']} above it, or add ".(3 - $group['offset']).'.)',
            };
            array_push($ids, ...$group['tile_ids']);
        }

        return $this->item('split_alignment', 'Split images line up on whole rows', $problems, tileIds: $ids);
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function account(GridPlan $plan): array
    {
        $account = $plan->socialAccount;
        $problem = match (true) {
            $account === null || $account->trashed() => 'The Instagram account was removed.',
            $account->connection_status !== ConnectionStatus::Connected => "{$account->handle} isn't connected. Reconnect it before the grid can go out.",
            default => null,
        };

        return $this->item('account', 'The Instagram account is connected', $problem === null ? [] : [$problem]);
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function gridCrop(GridPlan $plan): array
    {
        $problems = [];
        $ids = [];

        foreach ($plan->tiles as $tile) {
            // A Reel's cover is always cut from 9:16 to 3:4 — that's expected, not a warning.
            if ($tile->kind === GridTileKind::Reel) {
                continue;
            }

            $frame = $this->grids->frame($tile);

            if ($frame !== null && $frame->croppedOnGrid) {
                $problems[] = "Tile {$tile->position} loses ".max(1, (int) round($frame->hiddenShare * 100)).'% of the image on the grid.';
                $ids[] = $tile->id;
            }
        }

        return $this->item('grid_crop', 'Tiles show in full on the grid', $problems, soft: true, tileIds: $ids);
    }

    /**
     * Planned times go out in publish order (last position first), a minimum gap apart, and none
     * is in the past for a tile that hasn't gone out yet.
     *
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function dates(GridPlan $plan): array
    {
        $problems = [];
        $ids = [];
        $now = CarbonImmutable::now();
        $publishOrder = $plan->tiles->sortByDesc('position')->values();

        foreach ($publishOrder as $tile) {
            $at = $this->grids->publishAt($plan, $tile);
            $live = $this->grids->instagramTarget($plan, $tile)?->status === PostTargetStatus::Published;

            if ($at !== null && ! $live && $at->lessThan($now)) {
                $problems[] = "Tile {$tile->position} is planned in the past.";
                $ids[] = $tile->id;
            }
        }

        foreach (GridSequenceValidator::violations($plan, $this->grids) as $violation) {
            $problems[] = $violation['message'];
            array_push($ids, ...$violation['tile_ids']);
        }

        return $this->item('dates', 'Dates go out in grid order', $problems, soft: true, tileIds: array_values(array_unique($ids)));
    }

    /**
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function outside(GridLayout $layout): array
    {
        $count = count($layout->outside());

        return $this->item(
            'outside_posts',
            'No other posts land inside the grid',
            $count === 0 ? [] : [$count === 1
                ? '1 other post on this account goes out between grid dates. It will shift the grid by one.'
                : "{$count} other posts on this account go out between grid dates. They will shift the grid."],
            soft: true,
        );
    }

    /**
     * @param  list<string>  $problems
     * @param  list<int>  $tileIds
     * @return array{key: string, label: string, passed: bool, severity: string, soft: bool, detail: string|null, tile_ids: list<int>}
     */
    private function item(string $key, string $label, array $problems, bool $soft = false, array $tileIds = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $problems === [],
            'severity' => $soft ? 'soft' : 'hard',
            'soft' => $soft,
            'detail' => $problems === [] ? null : implode(' ', $problems),
            'tile_ids' => array_values(array_unique($tileIds)),
        ];
    }
}
