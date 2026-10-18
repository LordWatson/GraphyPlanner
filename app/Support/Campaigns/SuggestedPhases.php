<?php

namespace App\Support\Campaigns;

use App\Enums\CampaignColour;
use Carbon\CarbonImmutable;

/**
 * Step C-3.1 — the default phase split offered (never auto-created) for a dated campaign with no
 * phases: Teaser · Launch · Sustain · Wrap, across the campaign's range. Short campaigns get
 * fewer phases so none is shorter than a day.
 */
final class SuggestedPhases
{
    /**
     * Name, share of the range, colour.
     *
     * @var list<array{0: string, 1: float, 2: CampaignColour}>
     */
    private const SPLIT = [
        ['Teaser', 0.2, CampaignColour::Lilac],
        ['Launch', 0.2, CampaignColour::Teal],
        ['Sustain', 0.45, CampaignColour::Electric],
        ['Wrap', 0.15, CampaignColour::Lime],
    ];

    /**
     * @return list<array{name: string, starts_on: string, ends_on: string, colour: string}>
     */
    public static function between(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end) + 1;

        $split = match (true) {
            $days >= 4 => self::SPLIT,
            $days === 3 => [self::SPLIT[0], self::SPLIT[1], self::SPLIT[3]],
            $days === 2 => [self::SPLIT[1], self::SPLIT[3]],
            default => [self::SPLIT[1]],
        };

        $total = array_sum(array_column($split, 1));
        $phases = [];
        $cursor = $start;
        $remaining = $days;

        foreach ($split as $index => [$name, $share, $colour]) {
            $isLast = $index === count($split) - 1;
            $phasesLeft = count($split) - $index - 1;
            $length = $isLast
                ? $remaining
                : max(1, min($remaining - $phasesLeft, (int) round($days * $share / $total)));

            $phases[] = [
                'name' => $name,
                'starts_on' => $cursor->toDateString(),
                'ends_on' => $cursor->addDays($length - 1)->toDateString(),
                'colour' => $colour->value,
            ];

            $cursor = $cursor->addDays($length);
            $remaining -= $length;
        }

        return $phases;
    }
}
