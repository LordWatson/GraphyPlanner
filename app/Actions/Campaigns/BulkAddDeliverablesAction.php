<?php

namespace App\Actions\Campaigns;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.2 — "Add N per week on {platforms} through {phase}": spreads N slots per week, per
 * platform, evenly across each week of the phase (or the whole campaign when no phase is given),
 * in one transaction. Weekly slots fall on evenly spaced weekdays from the range's start.
 */
class BulkAddDeliverablesAction
{
    public const MAX_CREATED = 200;

    /**
     * @param  list<Platform>  $platforms
     * @return list<CampaignDeliverable>
     */
    public function __invoke(Campaign $campaign, int $perWeek, array $platforms, ?CampaignPhase $phase, ?string $time, ?string $titlePrefix, ?User $user): array
    {
        [$start, $end] = $this->range($campaign, $phase);
        $dates = $this->dates($start, $end, $perWeek);

        if (count($dates) * count($platforms) > self::MAX_CREATED) {
            throw ValidationException::withMessages(['per_week' => 'That would add more than '.self::MAX_CREATED.' deliverables. Narrow the range or the count.']);
        }

        $created = DB::transaction(function () use ($campaign, $dates, $platforms, $phase, $time, $titlePrefix, $user) {
            $sort = (int) $campaign->deliverables()->max('sort');
            $created = [];

            foreach ($dates as $index => $date) {
                foreach ($platforms as $platform) {
                    $created[] = $campaign->deliverables()->create([
                        'campaign_phase_id' => $phase?->id,
                        'title' => trim(($titlePrefix ?: ($phase->name ?? $campaign->name)).' · '.$platform->label().' '.($index + 1)),
                        'platform' => $platform,
                        'planned_for' => $date->toDateString(),
                        'planned_time' => $time,
                        'sort' => ++$sort,
                    ]);
                }
            }

            $campaign->recordActivity('deliverables.added', $user, meta: ['count' => count($created), 'phase_id' => $phase?->id]);

            return $created;
        });

        Log::info('Campaign deliverables bulk-added', [
            'campaign_id' => $campaign->id,
            'phase_id' => $phase?->id,
            'count' => count($created),
            'user_id' => $user?->id,
        ]);

        return $created;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Campaign $campaign, ?CampaignPhase $phase): array
    {
        $start = $phase->starts_on ?? $campaign->start_date;
        $end = $phase->ends_on ?? $campaign->end_date;

        if ($start === null || $end === null) {
            throw ValidationException::withMessages(['phase_id' => $phase
                ? 'Give this phase dates before bulk-adding to it.'
                : 'Set the campaign’s dates before bulk-adding.']);
        }

        return [CarbonImmutable::parse($start->toDateString()), CarbonImmutable::parse($end->toDateString())];
    }

    /**
     * `$perWeek` dates in each 7-day block of the range, spaced evenly within the block (clipped
     * to the range's end).
     *
     * @return list<CarbonImmutable>
     */
    private function dates(CarbonImmutable $start, CarbonImmutable $end, int $perWeek): array
    {
        $dates = [];
        $gap = 7 / $perWeek;

        for ($week = $start; $week->lessThanOrEqualTo($end); $week = $week->addDays(7)) {
            for ($i = 0; $i < $perWeek; $i++) {
                $date = $week->addDays((int) floor($i * $gap));

                if ($date->lessThanOrEqualTo($end)) {
                    $dates[] = $date;
                }
            }
        }

        return $dates;
    }
}
