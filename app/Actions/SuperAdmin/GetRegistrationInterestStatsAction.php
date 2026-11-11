<?php

namespace App\Actions\SuperAdmin;

use App\Enums\RegistrationInterestStatus;
use App\Models\RegistrationInterest;
use Illuminate\Support\Collection;

/**
 * The analytics strip above the registrations list: volume, pipeline split, conversion, speed to
 * first contact, where people come from, and a 30-day daily trend.
 *
 * shortcut: aggregates in PHP over the whole table (portable across MySQL/SQLite), fine for
 * hundreds of rows; move the counts into SQL if registrations reach the tens of thousands.
 */
class GetRegistrationInterestStatsAction
{
    private const TREND_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $rows = RegistrationInterest::query()
            ->get(['id', 'status', 'created_at', 'contacted_at', 'country', 'utm_source', 'referrer']);

        $total = $rows->count();
        $byStatus = $rows->countBy(fn (RegistrationInterest $row) => $row->status->value);
        $converted = ($byStatus[RegistrationInterestStatus::DemoBooked->value] ?? 0)
            + ($byStatus[RegistrationInterestStatus::Onboarded->value] ?? 0);

        $contactHours = $rows
            ->filter(fn (RegistrationInterest $row) => $row->contacted_at !== null)
            ->map(fn (RegistrationInterest $row) => $row->created_at->diffInMinutes($row->contacted_at) / 60);

        return [
            'total' => $total,
            'last7Days' => $rows->filter(fn (RegistrationInterest $row) => $row->created_at->gte(now()->subDays(7)))->count(),
            'last30Days' => $rows->filter(fn (RegistrationInterest $row) => $row->created_at->gte(now()->subDays(30)))->count(),
            'previous30Days' => $rows->filter(fn (RegistrationInterest $row) => $row->created_at->between(now()->subDays(60), now()->subDays(30)))->count(),
            'awaitingContact' => $byStatus[RegistrationInterestStatus::New->value] ?? 0,
            'demoRate' => $total > 0 ? (int) round($converted / $total * 100) : null,
            'onboardedRate' => $total > 0 ? (int) round(($byStatus[RegistrationInterestStatus::Onboarded->value] ?? 0) / $total * 100) : null,
            'avgHoursToContact' => $contactHours->isEmpty() ? null : round($contactHours->avg(), 1),
            'byStatus' => array_map(fn (RegistrationInterestStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'count' => $byStatus[$status->value] ?? 0,
            ], RegistrationInterestStatus::cases()),
            'topCountries' => $this->top($rows->map(fn (RegistrationInterest $row) => $row->country ?? 'Unknown')),
            'topSources' => $this->top($rows->map(fn (RegistrationInterest $row) => self::source($row))),
            'daily' => $this->daily($rows),
        ];
    }

    /** Where the visitor came from: the UTM source, else the referring site, else direct. */
    public static function source(RegistrationInterest $row): string
    {
        if ($row->utm_source) {
            return $row->utm_source;
        }

        $host = $row->referrer ? parse_url($row->referrer, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : 'Direct';
    }

    /**
     * @param  Collection<int, string>  $values
     * @return list<array{label: string, count: int}>
     */
    private function top(Collection $values, int $limit = 5): array
    {
        return array_values($values->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->all());
    }

    /**
     * @param  Collection<int, RegistrationInterest>  $rows
     * @return list<array{date: string, count: int}>
     */
    private function daily(Collection $rows): array
    {
        $counts = $rows->countBy(fn (RegistrationInterest $row) => $row->created_at->toDateString());

        return array_map(function (int $daysAgo) use ($counts) {
            $date = now()->subDays($daysAgo)->toDateString();

            return ['date' => $date, 'count' => $counts[$date] ?? 0];
        }, range(self::TREND_DAYS - 1, 0));
    }
}
