<?php

namespace Tests\Unit\Enums;

use App\Enums\MediaRatio;
use App\Enums\Platform;
use Tests\TestCase;

class MediaRatioTest extends TestCase
{
    public function test_every_channel_defaults_to_original_so_old_posts_publish_unchanged(): void
    {
        foreach (Platform::cases() as $platform) {
            $this->assertSame(MediaRatio::Original, MediaRatio::defaultFor($platform));
        }
    }

    public function test_resolve_reads_the_saved_format_for_a_channel(): void
    {
        $format = ['instagram' => '4:5', 'tiktok' => '9:16'];

        $this->assertSame(MediaRatio::Portrait, MediaRatio::resolve($format, Platform::Instagram));
        $this->assertSame(MediaRatio::Vertical, MediaRatio::resolve($format, Platform::TikTok));
        $this->assertSame(MediaRatio::Original, MediaRatio::resolve($format, Platform::LinkedIn));
        $this->assertSame(MediaRatio::Original, MediaRatio::resolve(null, Platform::Facebook));
    }

    public function test_resolve_falls_back_when_a_format_is_unknown_or_not_offered_on_that_channel(): void
    {
        $this->assertSame(MediaRatio::Original, MediaRatio::resolve(['instagram' => '9:16'], Platform::Instagram));
        $this->assertSame(MediaRatio::Original, MediaRatio::resolve(['linkedin' => '2:1'], Platform::LinkedIn));
    }

    public function test_quotients(): void
    {
        $this->assertNull(MediaRatio::Original->quotient());
        $this->assertSame(1.0, MediaRatio::Square->quotient());
        $this->assertEqualsWithDelta(0.8, MediaRatio::Portrait->quotient(), 1e-9);
        $this->assertEqualsWithDelta(0.5625, MediaRatio::Vertical->quotient(), 1e-9);
    }
}
