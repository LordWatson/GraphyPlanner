<?php

namespace Tests\Unit\Services\Grids;

use App\Enums\MediaRatio;
use App\Services\Grids\GridLayoutService;
use App\Support\Grids\GridLayoutTile;
use App\Support\Grids\GridOutsidePost;
use App\Support\Grids\GridTileFrame;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step 18.2 — the grid layout core: slots, rows, publish sequence and split alignment, plus the
 * grid frame maths. The shared fixture is also run by `resources/js/lib/grid-layout.test.ts`, so
 * PHP and TS can't drift.
 */
class GridLayoutServiceTest extends TestCase
{
    public function test_nothing_pinned_puts_position_one_top_left_and_publishes_it_last(): void
    {
        $layout = (new GridLayoutService)->layout($this->tiles(9));

        $first = $layout->tile(1);
        $ninth = $layout->tile(9);

        $this->assertSame(['slot' => 0, 'row' => 1, 'column' => 1, 'sequence' => 9], array_intersect_key($first ?? [], array_flip(['slot', 'row', 'column', 'sequence'])));
        $this->assertSame(['slot' => 8, 'row' => 3, 'column' => 3, 'sequence' => 1], array_intersect_key($ninth ?? [], array_flip(['slot', 'row', 'column', 'sequence'])));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function pinnedCounts(): array
    {
        return [
            'none pinned' => [0, 1, 1],
            'one pinned' => [1, 1, 2],
            'two pinned' => [2, 1, 3],
            'three pinned' => [3, 2, 1],
        ];
    }

    #[DataProvider('pinnedCounts')]
    public function test_pinned_posts_push_the_grid_along(int $pinned, int $row, int $column): void
    {
        $tile = (new GridLayoutService)->layout($this->tiles(9), $pinned)->tile(1);

        $this->assertSame($pinned, $tile['slot'] ?? null);
        $this->assertSame($row, $tile['row'] ?? null);
        $this->assertSame($column, $tile['column'] ?? null);
    }

    public function test_pinned_count_is_clamped_to_instagrams_three(): void
    {
        $this->assertSame(3, (new GridLayoutService)->layout($this->tiles(1), 7)->pinnedCount);
    }

    public function test_publish_sequence_is_the_reverse_of_position_whatever_the_input_order(): void
    {
        $layout = (new GridLayoutService)->layout([
            new GridLayoutTile(30, 3),
            new GridLayoutTile(10, 1),
            new GridLayoutTile(20, 2),
        ]);

        $this->assertSame([10, 20, 30], array_column($layout->slots, 'id'));
        $this->assertSame([3, 2, 1], array_column($layout->slots, 'sequence'));
    }

    public function test_split_alignment_aligned_off_by_one_off_by_two_and_stacked(): void
    {
        $service = new GridLayoutService;
        $split = fn (array $positions, string $group) => array_map(fn (int $p) => new GridLayoutTile($p, $p, $group), $positions);

        $aligned = $service->layout([...$split([1, 2, 3], 'a'), new GridLayoutTile(4, 4)]);
        $this->assertTrue($aligned->splitGroups[0]['aligned']);
        $this->assertSame([], $aligned->misalignedSplits());

        $offByOne = $service->layout($split([1, 2, 3], 'a'), pinnedCount: 1);
        $this->assertFalse($offByOne->splitGroups[0]['aligned']);
        $this->assertSame(1, $offByOne->splitGroups[0]['offset']);

        $offByTwo = $service->layout([new GridLayoutTile(1, 1), new GridLayoutTile(2, 2), ...$split([3, 4, 5], 'a')]);
        $this->assertFalse($offByTwo->splitGroups[0]['aligned']);
        $this->assertSame(2, $offByTwo->splitGroups[0]['offset']);

        $stacked = $service->layout([...$split([1, 2, 3], 'top'), ...$split([4, 5, 6, 7, 8, 9], 'bottom')]);
        $this->assertCount(2, $stacked->splitGroups);
        $this->assertSame([], $stacked->misalignedSplits());
        $this->assertSame([2, 3], $stacked->splitGroups[1]['rows']);
    }

    public function test_a_split_that_is_not_a_multiple_of_three_never_lines_up(): void
    {
        $layout = (new GridLayoutService)->layout(array_map(fn (int $p) => new GridLayoutTile($p, $p, 'a'), [1, 2]));

        $this->assertFalse($layout->splitGroups[0]['aligned']);
    }

    public function test_outside_posts_land_in_their_real_slot_and_can_break_a_split(): void
    {
        $at = fn (string $date) => CarbonImmutable::parse($date);
        $tiles = [
            new GridLayoutTile(1, 1, 'a', $at('2026-11-03 09:00')),
            new GridLayoutTile(2, 2, 'a', $at('2026-11-02 09:00')),
            new GridLayoutTile(3, 3, 'a', $at('2026-11-01 09:00')),
        ];

        $layout = (new GridLayoutService)->layout($tiles, 0, [new GridOutsidePost(99, $at('2026-11-02 12:00'))]);

        $this->assertSame(['tile', 'outside', 'tile', 'tile'], array_column($layout->slots, 'kind'));
        $this->assertSame(1, $layout->outside()[0]['slot']);
        $this->assertFalse($layout->splitGroups[0]['aligned']);
    }

    public function test_grid_frame_hand_checked_values(): void
    {
        // A 4:5 post shows 93.75% of its width on the 3:4 grid.
        $portrait = GridTileFrame::compute(1080, 1350, MediaRatio::Portrait);
        $this->assertSame(['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0], $portrait->frame);
        $this->assertSame(0.9375, $portrait->grid['w']);
        $this->assertTrue($portrait->croppedOnGrid);
        $this->assertSame(0.0625, $portrait->hiddenShare);

        // A 3:4 post fills the grid tile exactly.
        $classic = GridTileFrame::compute(1080, 1440, MediaRatio::Classic);
        $this->assertFalse($classic->croppedOnGrid);

        // A Reel (9:16) loses a quarter of its height on the grid.
        $reel = GridTileFrame::compute(1080, 1920, MediaRatio::Vertical);
        $this->assertSame(0.75, $reel->grid['h']);
        $this->assertSame(0.25, $reel->hiddenShare);

        // Landscape 1.91:1 keeps a narrow centre column.
        $wide = GridTileFrame::compute(1910, 1000, MediaRatio::Landscape);
        $this->assertEqualsWithDelta(0.75 / 1.91, $wide->grid['w'], 0.0001);
    }

    public function test_every_shared_fixture_layout_matches(): void
    {
        foreach ($this->fixture()['layouts'] as $case) {
            $tiles = array_map(fn (array $tile) => new GridLayoutTile(
                $tile['id'],
                $tile['position'],
                $tile['split_group_id'],
                $tile['publish_at'] !== null ? CarbonImmutable::parse($tile['publish_at']) : null,
            ), $case['tiles']);
            $outside = array_map(fn (array $post) => new GridOutsidePost($post['id'], CarbonImmutable::parse($post['publish_at'])), $case['outside']);

            $this->assertSame($case['expected'], (new GridLayoutService)->layout($tiles, $case['pinned'], $outside)->toArray(), $case['name']);
        }
    }

    public function test_every_shared_fixture_frame_matches(): void
    {
        foreach ($this->fixture()['frames'] as $case) {
            $result = GridTileFrame::compute(
                $case['width'],
                $case['height'],
                MediaRatio::from($case['format']),
                $case['crop']['x'],
                $case['crop']['y'],
                $case['crop']['zoom'],
            )->toArray();

            $this->assertEqualsWithDelta($case['expected'], $result, 0.0001, $case['name']);
        }
    }

    /**
     * @return list<GridLayoutTile>
     */
    private function tiles(int $count): array
    {
        return array_map(fn (int $position) => new GridLayoutTile($position, $position), range(1, $count));
    }

    /**
     * @return array{layouts: list<array<string, mixed>>, frames: list<array<string, mixed>>}
     */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/grid-layout.json')), true);
    }
}
