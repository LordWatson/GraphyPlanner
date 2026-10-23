<?php

namespace App\Services\AI;

use App\Enums\AiRating;
use App\Models\AiFeedback;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Step M-7.1 (dev plan Step 2.9) — turns the raw `ai_feedback` rows into an internal-only "how well
 * is AI landing?" picture for an org: the acceptance rate (helpful/used over everything rated), the
 * raw counts, and the most common reasons people gave for a thumbs-down, so the team can tune the
 * prompts. Strictly org-scoped. No suggestion text is stored or read — only ratings and reasons.
 */
class AiPerformanceService
{
    /**
     * @return array{total: int, positive: int, negative: int, applied: int, acceptance_rate: float|null, top_dismissal_reasons: list<array{reason: string, count: int}>}
     */
    public function forOrg(Organization $organization, ?CarbonImmutable $since = null): array
    {
        $query = AiFeedback::query()->where('org_id', $organization->id);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        /** @var Collection<int, AiFeedback> $feedback */
        $feedback = $query->get(['rating', 'reason']);

        $total = $feedback->count();
        $positive = $feedback->filter(fn (AiFeedback $f) => $f->rating->isPositive())->count();
        $applied = $feedback->filter(fn (AiFeedback $f) => $f->rating === AiRating::Applied)->count();
        $negative = $total - $positive;

        $reasons = $feedback
            ->filter(fn (AiFeedback $f) => $f->rating === AiRating::Down && filled($f->reason))
            ->groupBy(fn (AiFeedback $f) => mb_strtolower(trim((string) $f->reason)))
            ->map(fn ($group) => [
                'reason' => (string) $group->first()->reason,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $reasons = array_values($reasons->all());

        return [
            'total' => $total,
            'positive' => $positive,
            'negative' => $negative,
            'applied' => $applied,
            'acceptance_rate' => $total === 0 ? null : round($positive / $total, 2),
            'top_dismissal_reasons' => $reasons,
        ];
    }
}
