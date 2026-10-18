<?php

namespace App\Support\Campaigns;

use App\Models\Campaign;
use Carbon\CarbonImmutable;

/**
 * Step C-0.4 — where a campaign sits in time, from its dates alone (plan §1.3.3: status is
 * intent, dates are fact). Computed server-side in the organization's timezone so the studio,
 * the hub and the client portal all say the same thing.
 *
 * - `upcoming`: starts after today.
 * - `live`: started, and the end date (if any) is today or later.
 * - `ended`: the end date has passed.
 * - `undated`: no start date.
 */
final readonly class CampaignTimeline
{
    public const UPCOMING = 'upcoming';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    public const UNDATED = 'undated';

    private function __construct(
        public string $state,
        public ?int $daysToLaunch,
        public ?int $daysLeft,
        public ?int $ranFor,
        public ?int $endedDaysAgo,
    ) {}

    public static function for(Campaign $campaign, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();
        $start = $campaign->start_date ? CarbonImmutable::parse($campaign->start_date->toDateString(), $today->getTimezone()) : null;
        $end = $campaign->end_date ? CarbonImmutable::parse($campaign->end_date->toDateString(), $today->getTimezone()) : null;

        if ($start === null) {
            return new self(self::UNDATED, null, null, null, null);
        }

        if ($start->greaterThan($today)) {
            return new self(self::UPCOMING, (int) $today->diffInDays($start), null, null, null);
        }

        if ($end !== null && $end->lessThan($today)) {
            return new self(self::ENDED, null, null, (int) $start->diffInDays($end) + 1, (int) $end->diffInDays($today));
        }

        return new self(self::LIVE, null, $end !== null ? (int) $today->diffInDays($end) : null, null, null);
    }

    /**
     * The one line the UI shows under a campaign's name.
     */
    public function sentence(): string
    {
        return match ($this->state) {
            self::UPCOMING => match ($this->daysToLaunch) {
                1 => 'Launches tomorrow.',
                default => "Launches in {$this->daysToLaunch} days.",
            },
            self::LIVE => match ($this->daysLeft) {
                null => 'Live. No end date.',
                0 => 'Live. Last day today.',
                1 => 'Live. 1 day left.',
                default => "Live. {$this->daysLeft} days left.",
            },
            self::ENDED => $this->ranFor === 1 ? 'Ran for 1 day.' : "Ran for {$this->ranFor} days.",
            default => 'No dates yet.',
        };
    }

    /**
     * @return array{state: string, sentence: string, days_to_launch: int|null, days_left: int|null, ran_for: int|null, ended_days_ago: int|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'sentence' => $this->sentence(),
            'days_to_launch' => $this->daysToLaunch,
            'days_left' => $this->daysLeft,
            'ran_for' => $this->ranFor,
            'ended_days_ago' => $this->endedDaysAgo,
        ];
    }
}
