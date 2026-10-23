<?php

namespace App\Services\AI;

use App\Contracts\EventCalendar;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\IdeaContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Step B-6.1 — builds the {@see IdeaContext} that tunes the idea engine to a brand's place and
 * moment: its timezone, location and country, the current season, the next few weeks of local
 * holidays/awareness days (via the {@see EventCalendar} contract), its content pillars, and the
 * titles of what it recently posted so the model avoids repeats.
 *
 * All reads are small and bounded (a couple of indexed lookups scoped to the one brand), so this is
 * cheap to run on every "More ideas" tap and in the nightly per-brand job.
 */
class IdeaContextBuilder
{
    /** How many recent idea/post titles to feed the model as "already covered". */
    private const RECENT_TITLES = 12;

    /** Countries that sit in the Southern hemisphere for the season calculation. */
    private const SOUTHERN = ['AU', 'NZ', 'ZA', 'AR', 'CL', 'UY', 'BR', 'PE', 'BO', 'PY'];

    /** A few common country names mapped to ISO alpha-2 codes, so free-text fields still resolve. */
    private const COUNTRY_NAMES = [
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB',
        'united states' => 'US', 'united states of america' => 'US', 'usa' => 'US', 'us' => 'US', 'america' => 'US',
    ];

    public function __construct(private readonly EventCalendar $events) {}

    public function build(Client $brand): IdeaContext
    {
        $timezone = $this->timezone($brand);
        $country = $this->country($brand);
        $now = CarbonImmutable::now($timezone);
        $windowDays = max(1, (int) config('ai.ideas.event_window_days', 21));

        return new IdeaContext(
            timezone: $timezone,
            location: $this->clean($brand->location),
            country: $country,
            season: $this->season($country, $now),
            events: $this->events->upcoming($country, $now, $windowDays),
            recentTitles: $this->recentTitles($brand),
            pillars: $this->pillars($brand),
        );
    }

    private function timezone(Client $brand): string
    {
        $timezone = $brand->organization->default_timezone ?? config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * The brand's country as an ISO alpha-2 code, from its `countries` list first, then its free-text
     * `location`, then the launch default (`ai.ideas.default_country`), so a brand that never filled
     * a country still gets the home market's calendar rather than global-only.
     */
    private function country(Client $brand): ?string
    {
        foreach ($brand->countries ?? [] as $candidate) {
            if ($code = $this->toCountryCode($candidate)) {
                return $code;
            }
        }

        if (($code = $this->toCountryCode($brand->location)) !== null) {
            return $code;
        }

        $default = trim((string) config('ai.ideas.default_country', 'GB'));

        return $default === '' ? null : strtoupper($default);
    }

    private function toCountryCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $value)) {
            return strtoupper($value);
        }

        // Pick the last comma-separated token ("Bristol, United Kingdom" → "United Kingdom").
        $tail = trim((string) Str::afterLast($value, ','));

        foreach ([$tail, $value] as $candidate) {
            $key = strtolower($candidate);

            if (isset(self::COUNTRY_NAMES[$key])) {
                return self::COUNTRY_NAMES[$key];
            }
        }

        return null;
    }

    private function season(?string $country, CarbonImmutable $now): string
    {
        $month = (int) $now->month;
        $northern = ['winter', 'winter', 'spring', 'spring', 'spring', 'summer', 'summer', 'summer', 'autumn', 'autumn', 'autumn', 'winter'];
        $season = $northern[$month - 1];

        if ($country !== null && in_array($country, self::SOUTHERN, true)) {
            $season = match ($season) {
                'winter' => 'summer',
                'spring' => 'autumn',
                'summer' => 'winter',
                default => 'spring',
            };
        }

        return $season;
    }

    /**
     * @return list<string>
     */
    private function recentTitles(Client $brand): array
    {
        $ideaTitles = $brand->aiPostIdeas()
            ->latest('id')
            ->limit(self::RECENT_TITLES)
            ->pluck('title')
            ->all();

        $postTitles = Post::query()
            ->where('org_id', $brand->org_id)
            ->where('client_id', $brand->id)
            ->whereNotNull('master_caption')
            ->latest('id')
            ->limit(self::RECENT_TITLES)
            ->pluck('master_caption')
            ->map(fn (string $caption) => Str::limit(trim((string) Str::before($caption, "\n")), 60))
            ->all();

        $titles = array_values(array_unique(array_filter(array_map(
            fn (string $title) => trim($title),
            [...$ideaTitles, ...$postTitles],
        ), fn (string $title) => $title !== '')));

        return array_slice($titles, 0, self::RECENT_TITLES);
    }

    /**
     * @return list<string>
     */
    private function pillars(Client $brand): array
    {
        $pillars = $brand->brandBrain->content_pillars ?? [];

        return array_values(array_map('strval', $pillars));
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
