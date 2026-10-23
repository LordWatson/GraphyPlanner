<?php

namespace App\Support\AI;

use App\Services\AI\DeepSeekProvider;
use App\Services\AI\IdeaContextBuilder;
use App\Support\Events\EventOccurrence;
use Carbon\CarbonImmutable;

/**
 * Step B-6.1 — the local/seasonal grounding for the idea engine, assembled by
 * {@see IdeaContextBuilder} and rendered into the prompt by
 * {@see DeepSeekProvider::recommendLocalPosts()}.
 *
 * It carries the facts that make an idea feel like *this* brand, *this* week: where they are, what
 * season it is, which local holidays/awareness days are coming up, what they post about, and what
 * they've recently posted (so the model doesn't repeat itself). Immutable and self-rendering so the
 * prompt wording lives in one place.
 */
final class IdeaContext
{
    /**
     * @param  list<EventOccurrence>  $events  upcoming local/global events, soonest first
     * @param  list<string>  $recentTitles  recent idea/post titles the model should avoid repeating
     * @param  list<string>  $pillars  the brand's content pillars
     */
    public function __construct(
        public readonly string $timezone,
        public readonly ?string $location = null,
        public readonly ?string $country = null,
        public readonly ?string $season = null,
        public readonly array $events = [],
        public readonly array $recentTitles = [],
        public readonly array $pillars = [],
    ) {}

    /**
     * Render the context as a markdown prompt block. The caller (the provider) scrubs the result
     * before it reaches the vendor, so brand-typed text (location, recent titles) is PII-safe.
     */
    public function toPromptBlock(): string
    {
        $today = CarbonImmutable::now($this->timezone);

        $lines = ['# Local & seasonal context', "- Today (brand local time): {$today->toFormattedDayDateString()}"];

        if ($this->location !== null && $this->location !== '') {
            $lines[] = "- Location: {$this->location}";
        }

        if ($this->season !== null) {
            $lines[] = "- Season: {$this->season}";
        }

        if ($this->events !== []) {
            $lines[] = '- Upcoming local dates (tie at least one idea to a relevant one, in the brand voice — do not force it):';

            foreach ($this->events as $event) {
                $when = CarbonImmutable::parse($event->date);
                $lines[] = "  - {$event->date} ({$when->dayName}): {$event->name}";
            }
        }

        if ($this->pillars !== []) {
            $lines[] = '- Content pillars: '.implode(', ', $this->pillars);
        }

        if ($this->recentTitles !== []) {
            $lines[] = '- Recently covered (do NOT repeat these angles): '.implode('; ', $this->recentTitles);
        }

        return implode("\n", $lines);
    }
}
