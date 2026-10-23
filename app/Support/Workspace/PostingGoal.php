<?php

namespace App\Support\Workspace;

/**
 * Step B-4.5 — the primary brand's posting goal, parsed from the `clients.posting_goal` JSON column.
 *
 * A small, immutable value object so the shape lives in one place: the Today dashboard's "Gaps"
 * section (B-4.3), the calendar's ghost slots, the AI weekly planner (B-6.3) and the brand editor
 * all read it the same way. `preferredDays` are ISO-8601 weekday numbers (1 = Monday … 7 = Sunday),
 * so they line up with Carbon's `dayOfWeekIso`.
 */
final class PostingGoal
{
    /**
     * @param  list<int>  $preferredDays  ISO-8601 weekday numbers (1 = Mon … 7 = Sun)
     */
    public function __construct(
        public readonly ?int $postsPerWeek = null,
        public readonly array $preferredDays = [],
    ) {}

    /**
     * Build from the stored JSON array (or null). Unknown/invalid values are dropped rather than
     * throwing, so a hand-edited or legacy row never breaks the dashboard.
     *
     * @param  array<string, mixed>|null  $value
     */
    public static function fromArray(?array $value): self
    {
        if ($value === null) {
            return new self;
        }

        $postsPerWeek = isset($value['posts_per_week']) && is_numeric($value['posts_per_week'])
            ? max(0, (int) $value['posts_per_week'])
            : null;

        $preferredDays = [];

        foreach ((array) ($value['preferred_days'] ?? []) as $day) {
            if (is_numeric($day) && (int) $day >= 1 && (int) $day <= 7) {
                $preferredDays[(int) $day] = (int) $day;
            }
        }

        sort($preferredDays);

        return new self($postsPerWeek, $preferredDays);
    }

    /**
     * True when the brand has actually set a goal (a zero/empty goal is treated as "no goal", so
     * the dashboard doesn't nag a brand that never opted in).
     */
    public function isSet(): bool
    {
        return ($this->postsPerWeek !== null && $this->postsPerWeek > 0)
            || $this->preferredDays !== [];
    }

    /**
     * @return array{posts_per_week: int|null, preferred_days: list<int>}
     */
    public function toArray(): array
    {
        return [
            'posts_per_week' => $this->postsPerWeek,
            'preferred_days' => $this->preferredDays,
        ];
    }
}
