<?php

namespace Tests\Unit\Services\Events;

use App\Services\Events\StaticEventCalendar;
use App\Support\Events\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StaticEventCalendarTest extends TestCase
{
    private function names(array $events): array
    {
        return array_map(fn (EventOccurrence $event) => $event->name, $events);
    }

    public function test_it_returns_global_and_country_events_within_the_window_sorted_by_date(): void
    {
        $events = (new StaticEventCalendar)->upcoming('GB', CarbonImmutable::parse('2026-02-01'), 21);

        $names = $this->names($events);
        // Global Valentine's Day (14th) and the GB-only Pancake Day (17th) both fall in the window.
        $this->assertContains("Valentine's Day", $names);
        $this->assertContains('Pancake Day (Shrove Tuesday)', $names);

        // Sorted soonest first.
        $dates = array_map(fn (EventOccurrence $event) => $event->date, $events);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_a_country_only_event_is_hidden_from_other_countries(): void
    {
        $from = CarbonImmutable::parse('2026-02-01');

        $us = $this->names((new StaticEventCalendar)->upcoming('US', $from, 21));
        $this->assertContains("Valentine's Day", $us, 'global events still show');
        $this->assertNotContains('Pancake Day (Shrove Tuesday)', $us, 'a GB-only event never reaches a US brand');
    }

    public function test_a_null_country_returns_global_events_only(): void
    {
        $names = $this->names((new StaticEventCalendar)->upcoming(null, CarbonImmutable::parse('2026-02-01'), 21));

        $this->assertContains("Valentine's Day", $names);
        $this->assertNotContains('Pancake Day (Shrove Tuesday)', $names);
    }

    public function test_it_computes_easter_derived_dates_correctly(): void
    {
        // Easter Sunday 2026 is 5 April; Shrove Tuesday is 47 days before, i.e. 17 Feb 2026.
        $events = (new StaticEventCalendar)->upcoming('GB', CarbonImmutable::parse('2026-02-15'), 7);

        $pancake = collect($events)->firstWhere('name', 'Pancake Day (Shrove Tuesday)');
        $this->assertNotNull($pancake);
        $this->assertSame('2026-02-17', $pancake->date);
    }

    public function test_the_window_crosses_a_year_boundary(): void
    {
        $names = $this->names((new StaticEventCalendar)->upcoming('GB', CarbonImmutable::parse('2026-12-28'), 10));

        // New Year's Eve (this year) and New Year's Day (next year) both land in the window.
        $this->assertContains("New Year's Eve", $names);
        $this->assertContains("New Year's Day", $names);
    }

    public function test_nth_and_last_weekday_rules_resolve(): void
    {
        // US Thanksgiving 2026 is the 4th Thursday of November = 26 Nov; Black Friday is the day after.
        $events = (new StaticEventCalendar)->upcoming('US', CarbonImmutable::parse('2026-11-20'), 14);
        $byName = collect($events)->keyBy('name');

        $this->assertSame('2026-11-26', $byName['Thanksgiving']->date ?? null);
        $this->assertSame('2026-11-27', $byName['Black Friday']->date ?? null);
    }

    public function test_results_are_cached_per_year(): void
    {
        Cache::flush();
        $calendar = new StaticEventCalendar;

        $calendar->upcoming('GB', CarbonImmutable::parse('2026-02-01'), 21);
        $this->assertNotNull(Cache::get('events:calendar:2026'));

        // A second call reuses the cache and returns the same shape.
        $again = $calendar->upcoming('GB', CarbonImmutable::parse('2026-02-01'), 21);
        $this->assertNotEmpty($again);
    }

    public function test_it_never_throws_and_clamps_an_out_of_range_window(): void
    {
        $events = (new StaticEventCalendar)->upcoming('GB', CarbonImmutable::parse('2026-01-01'), 100000);

        $this->assertNotEmpty($events);
    }
}
