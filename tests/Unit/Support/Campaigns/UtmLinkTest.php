<?php

namespace Tests\Unit\Support\Campaigns;

use App\Support\Campaigns\DeliverableDueDate;
use App\Support\Campaigns\UtmLink;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Campaigns enhancement C-6.2 / C-4.3 — the UTM builder and the working-day due date.
 */
class UtmLinkTest extends TestCase
{
    public function test_it_adds_utm_tags_to_a_bare_url(): void
    {
        $this->assertSame(
            'https://example.com/spring?utm_source=instagram&utm_medium=social&utm_campaign=spring-launch',
            UtmLink::build('https://example.com/spring', 'spring-launch', 'instagram'),
        );
    }

    public function test_it_keeps_existing_params_and_the_fragment_and_replaces_old_utm_tags(): void
    {
        $this->assertSame(
            'https://example.com/p?ref=bio&utm_source=tiktok&utm_medium=social&utm_campaign=spr26#shop',
            UtmLink::build('https://example.com/p?ref=bio&utm_source=old&utm_campaign=old#shop', 'spr26', 'tiktok'),
        );
    }

    public function test_it_encodes_spaces_safely(): void
    {
        $this->assertStringContainsString('utm_campaign=summer%20sale', UtmLink::build('https://example.com', 'summer sale', 'facebook'));
    }

    public function test_the_due_date_skips_weekends(): void
    {
        $monday = CarbonImmutable::parse('2026-10-26');

        $this->assertSame('2026-10-23', DeliverableDueDate::for($monday, 1)?->toDateString());
        $this->assertSame('2026-10-21', DeliverableDueDate::for($monday, 3)?->toDateString());
        $this->assertSame('2026-10-26', DeliverableDueDate::for($monday, 0)?->toDateString());
        $this->assertNull(DeliverableDueDate::for(null, 3));
    }
}
