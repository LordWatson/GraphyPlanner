<?php

namespace App\Services\Events;

use App\Contracts\EventCalendar;
use App\Support\Events\EventOccurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Step B-6.1 — the first {@see EventCalendar}: a hand-curated static dataset of public holidays and
 * marketing-relevant awareness days for the launch countries (United Kingdom, United States) plus a
 * global set every brand gets (open question §5.5 — a paid API can replace this later behind the same
 * contract, and §5.5's launch-country scope is recorded here).
 *
 * Each definition resolves its own date for a given year, so movable feasts are correct every year:
 *  - `fixed`  — a fixed month/day (Christmas, Valentine's Day);
 *  - `nth`    — the nth given weekday of a month, n negative counting from the end (US Thanksgiving);
 *  - `easter` — a day offset from Easter Sunday (Pancake Day, Good Friday), computed with the
 *               Anonymous Gregorian algorithm so no `ext-calendar` dependency is needed.
 *
 * A computed year of events is cached (`events.cache_ttl_hours`), keyed by country + year, since the
 * set only changes when the dataset or the code does.
 */
class StaticEventCalendar implements EventCalendar
{
    /**
     * The curated dataset. Each entry: name, type, the countries it applies to (`['*']` = global),
     * and a `rule` the resolver turns into a date.
     *
     * @var list<array{name: string, type: string, countries: list<string>, rule: array<int, int|string>}>
     */
    private const EVENTS = [
        // --- Global (every brand) -------------------------------------------------------------
        ['name' => "New Year's Day", 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['*'], 'rule' => ['fixed', 1, 1]],
        ['name' => 'Valentine\'s Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 2, 14]],
        ['name' => 'International Women\'s Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 3, 8]],
        ['name' => 'St Patrick\'s Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 3, 17]],
        ['name' => 'Earth Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 4, 22]],
        ['name' => 'Pride Month begins', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 6, 1]],
        ['name' => 'World Mental Health Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 10, 10]],
        ['name' => 'Halloween', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 10, 31]],
        ['name' => 'Small Business Saturday', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['nth', 11, 6, -1]],
        ['name' => 'Christmas Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['*'], 'rule' => ['fixed', 12, 25]],
        ['name' => 'New Year\'s Eve', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['*'], 'rule' => ['fixed', 12, 31]],

        // --- United Kingdom -------------------------------------------------------------------
        ['name' => 'Pancake Day (Shrove Tuesday)', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['GB'], 'rule' => ['easter', -47]],
        ['name' => 'Mothering Sunday (UK Mother\'s Day)', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['GB'], 'rule' => ['easter', -21]],
        ['name' => 'Good Friday', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['easter', -2]],
        ['name' => 'Easter Monday', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['easter', 1]],
        ['name' => 'Early May bank holiday', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['nth', 5, 1, 1]],
        ['name' => 'Spring bank holiday', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['nth', 5, 1, -1]],
        ['name' => 'Father\'s Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['GB', 'US'], 'rule' => ['nth', 6, 7, 3]],
        ['name' => 'Summer bank holiday', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['nth', 8, 1, -1]],
        ['name' => 'Bonfire Night', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['GB'], 'rule' => ['fixed', 11, 5]],
        ['name' => 'Boxing Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['GB'], 'rule' => ['fixed', 12, 26]],

        // --- United States --------------------------------------------------------------------
        ['name' => 'Martin Luther King Jr. Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['nth', 1, 1, 3]],
        ['name' => 'Super Bowl Sunday', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['US'], 'rule' => ['nth', 2, 7, 2]],
        ['name' => 'Presidents\' Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['nth', 2, 1, 3]],
        ['name' => 'Mother\'s Day', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['US'], 'rule' => ['nth', 5, 7, 2]],
        ['name' => 'Memorial Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['nth', 5, 1, -1]],
        ['name' => 'Juneteenth', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['fixed', 6, 19]],
        ['name' => 'Independence Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['fixed', 7, 4]],
        ['name' => 'Labor Day', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['nth', 9, 1, 1]],
        ['name' => 'Thanksgiving', 'type' => EventOccurrence::TYPE_HOLIDAY, 'countries' => ['US'], 'rule' => ['nth', 11, 4, 4]],
        ['name' => 'Black Friday', 'type' => EventOccurrence::TYPE_AWARENESS, 'countries' => ['US', 'GB'], 'rule' => ['black_friday']],
    ];

    public function upcoming(?string $country, CarbonInterface $from, int $days): array
    {
        $days = max(1, min(366, $days));
        $start = CarbonImmutable::parse($from->toDateString());
        $end = $start->addDays($days);
        $country = $country !== null ? strtoupper(trim($country)) : null;

        $events = [];

        // Walk the window's calendar years so an event just over a New Year boundary is still found.
        foreach (range((int) $start->year, (int) $end->year) as $year) {
            foreach (self::yearEvents($year) as $event) {
                if (! $this->appliesToCountry($event->country, $country)) {
                    continue;
                }

                $date = CarbonImmutable::parse($event->date);

                if ($date->gte($start) && $date->lt($end)) {
                    $events[] = $event;
                }
            }
        }

        usort($events, fn (EventOccurrence $a, EventOccurrence $b) => [$a->date, $a->name] <=> [$b->date, $b->name]);

        return $events;
    }

    /**
     * Every event resolved for one calendar year, cached since it only changes with the dataset/code.
     * The cache holds plain arrays (not DTOs), so a serializing cache store can't hand back an
     * incomplete object after the class shape changes — the same lesson as the trends cache.
     *
     * @return list<EventOccurrence>
     */
    private static function yearEvents(int $year): array
    {
        $ttl = (int) config('ai.ideas.event_cache_ttl_hours', 168);

        /** @var list<array{date: string, name: string, type: string, country: string|null}> $rows */
        $rows = Cache::remember(
            "events:calendar:{$year}",
            now()->addHours(max(1, $ttl)),
            fn () => array_map(
                fn (array $event): array => [
                    'date' => self::resolveDate($event['rule'], $year)->toDateString(),
                    'name' => $event['name'],
                    'type' => $event['type'],
                    'country' => $event['countries'] === ['*'] ? null : $event['countries'][0],
                ],
                // A multi-country event becomes one occurrence per country so each brand sees it once.
                self::expandCountries(),
            ),
        );

        return array_map(
            fn (array $row): EventOccurrence => new EventOccurrence($row['date'], $row['name'], $row['type'], $row['country']),
            $rows,
        );
    }

    /**
     * Flatten multi-country definitions to one row per country (global stays a single `['*']` row),
     * so {@see upcoming()} can match on a single country code.
     *
     * @return list<array{name: string, type: string, countries: list<string>, rule: array<int, int|string>}>
     */
    private static function expandCountries(): array
    {
        $rows = [];

        foreach (self::EVENTS as $event) {
            if ($event['countries'] === ['*']) {
                $rows[] = $event;

                continue;
            }

            foreach ($event['countries'] as $country) {
                $rows[] = [...$event, 'countries' => [$country]];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, int|string>  $rule
     */
    private static function resolveDate(array $rule, int $year): CarbonImmutable
    {
        $kind = $rule[0];

        return match ($kind) {
            'fixed' => CarbonImmutable::create($year, (int) $rule[1], (int) $rule[2]),
            'nth' => self::nthWeekday($year, (int) $rule[1], (int) $rule[2], (int) $rule[3]),
            'easter' => self::easter($year)->addDays((int) $rule[1]),
            // Black Friday is the day after US Thanksgiving (4th Thursday of November).
            'black_friday' => self::nthWeekday($year, 11, 4, 4)->addDay(),
            default => CarbonImmutable::create($year, 1, 1),
        };
    }

    /**
     * The nth `$isoWeekday` (1 = Mon … 7 = Sun) of a month; a negative `$n` counts from the month's
     * end (e.g. -1 = the last).
     */
    private static function nthWeekday(int $year, int $month, int $isoWeekday, int $n): CarbonImmutable
    {
        if ($n > 0) {
            $date = CarbonImmutable::create($year, $month, 1);
            $offset = ($isoWeekday - $date->dayOfWeekIso + 7) % 7;

            return $date->addDays($offset + ($n - 1) * 7);
        }

        $date = CarbonImmutable::create($year, $month, 1)->endOfMonth();
        $offset = ($date->dayOfWeekIso - $isoWeekday + 7) % 7;

        return $date->subDays($offset + (abs($n) - 1) * 7);
    }

    /**
     * Easter Sunday for the given year, via the Anonymous Gregorian ("Meeus/Jones/Butcher") algorithm
     * — no `ext-calendar` needed, so it works on every deploy and in the SQLite test run.
     */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }

    private function appliesToCountry(?string $eventCountry, ?string $brandCountry): bool
    {
        if ($eventCountry === null) {
            return true;
        }

        return $brandCountry !== null && $eventCountry === $brandCountry;
    }
}
