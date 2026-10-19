<?php

namespace Tests\Unit\Support\Media;

use App\Support\Media\CropBox;
use PHPUnit\Framework\TestCase;

class CropBoxTest extends TestCase
{
    public function test_a_landscape_image_cropped_square_keeps_full_height_centred(): void
    {
        $box = CropBox::compute(1600, 900, 1.0);

        $this->assertSame([350, 0, 900, 900], [$box->x, $box->y, $box->width, $box->height]);
    }

    public function test_a_portrait_crop_of_a_square_image_keeps_full_width(): void
    {
        $box = CropBox::compute(1000, 1000, 4 / 5);

        $this->assertSame([100, 0, 800, 1000], [$box->x, $box->y, $box->width, $box->height]);
    }

    public function test_a_wide_crop_of_a_portrait_image_follows_the_focus_point(): void
    {
        $box = CropBox::compute(1000, 2000, 1.91, focusY: 0.25);

        // 1000 × 524 box centred on y = 500.
        $this->assertSame(1000, $box->width);
        $this->assertSame(524, $box->height);
        $this->assertSame(238, $box->y);
    }

    public function test_the_box_is_clamped_inside_the_image_when_the_focus_is_near_an_edge(): void
    {
        $box = CropBox::compute(1600, 900, 1.0, focusX: 1.0);

        $this->assertSame(700, $box->x);
        $this->assertSame(1600, $box->x + $box->width);
    }

    public function test_zoom_shrinks_the_box_around_the_focus_point(): void
    {
        $box = CropBox::compute(1000, 1000, 1.0, focusX: 0.5, focusY: 0.5, zoom: 2.0);

        $this->assertSame([250, 250, 500, 500], [$box->x, $box->y, $box->width, $box->height]);
    }

    public function test_out_of_range_inputs_are_clamped(): void
    {
        $box = CropBox::compute(1000, 1000, 1.0, focusX: -3, focusY: 9, zoom: 10);

        // Zoom caps at 3×, the focus at the image edges.
        $this->assertSame(333, $box->width);
        $this->assertSame(0, $box->x);
        $this->assertSame(1000 - 333, $box->y);
    }

    public function test_covers_whole_is_true_only_for_the_full_image(): void
    {
        $this->assertTrue(CropBox::compute(800, 1000, 4 / 5)->coversWhole(800, 1000));
        $this->assertFalse(CropBox::compute(1000, 1000, 4 / 5)->coversWhole(1000, 1000));
    }
}
