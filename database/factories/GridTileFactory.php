<?php

namespace Database\Factories;

use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Models\GridPlan;
use App\Models\GridTile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridTile>
 */
class GridTileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grid_plan_id' => GridPlan::factory(),
            'position' => 1,
            'kind' => GridTileKind::Image,
            'format' => MediaRatio::Classic,
        ];
    }

    public function placeholder(): static
    {
        return $this->state(fn () => ['kind' => GridTileKind::Placeholder]);
    }

    public function reel(): static
    {
        return $this->state(fn () => ['kind' => GridTileKind::Reel, 'format' => MediaRatio::Vertical]);
    }

    public function carousel(): static
    {
        return $this->state(fn () => ['kind' => GridTileKind::Carousel]);
    }
}
