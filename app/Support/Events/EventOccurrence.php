<?php

namespace App\Support\Events;

use App\Contracts\EventCalendar;
use JsonSerializable;

/**
 * Step B-6.1 — one dated event (a public holiday, an awareness day or a season marker) an
 * {@see EventCalendar} resolves for a brand's country and window. Immutable, so a
 * cached year of events can be shared safely; JSON-serializable so it can ride in the idea-of-the-day
 * cache and in Inertia props.
 */
final class EventOccurrence implements JsonSerializable
{
    public const TYPE_HOLIDAY = 'holiday';

    public const TYPE_AWARENESS = 'awareness';

    public const TYPE_SEASONAL = 'seasonal';

    /**
     * @param  string  $date  the event's calendar date, `Y-m-d`
     * @param  string  $name  the human name ("Pancake Day")
     * @param  string  $type  one of the TYPE_* constants
     * @param  string|null  $country  ISO-3166 alpha-2 code, or null for a global event
     */
    public function __construct(
        public readonly string $date,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $country = null,
    ) {}

    /**
     * @return array{date: string, name: string, type: string, country: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'name' => $this->name,
            'type' => $this->type,
            'country' => $this->country,
        ];
    }
}
