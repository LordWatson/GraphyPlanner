<?php

namespace App\Services\Analytics;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;

/**
 * Step 17.3 — throughput per staff member, so an Owner can spot an overloaded designer and plan
 * around it. **Workload, not a leaderboard**: everyone on staff is listed (including people with
 * nothing in range), sorted by name, with no ranks, scores or "top performer" framing.
 *
 * Client-side decisions — approvals/change requests made through a review link (no `user_id`) or
 * by a portal `ClientReviewer` — are reported as one separate figure, never attributed to staff.
 * System transitions (the publish job's `publishing → published`) have no actor and aren't counted.
 */
class TeamWorkloadMetrics
{
    /**
     * @return array{members: list<array{id: int, name: string, role: string, role_label: string, posts_created: int, transitions: int, sent_to_client: int, scheduled: int}>, client_decisions: int, totals: array{posts_created: int, transitions: int, sent_to_client: int, scheduled: int}}
     */
    public function summary(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        $staff = User::query()
            ->whereIn('org_id', $scope->orgIds)
            ->whereNotIn('role', [Role::ClientReviewer, Role::SuperAdmin])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'role']);

        $created = $scope->posts()
            ->whereBetween('posts.created_at', $range->utcBounds())
            ->whereNotNull('posts.created_by')
            ->toBase()
            ->selectRaw('posts.created_by as user_id, COUNT(*) as aggregate')
            ->groupBy('posts.created_by')
            ->pluck('aggregate', 'user_id');

        $transitions = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $scope->posts()->select('posts.id'))
            ->whereBetween('created_at', $range->utcBounds())
            ->whereNotNull('user_id')
            ->toBase()
            ->selectRaw('user_id, to_status, COUNT(*) as aggregate')
            ->groupBy('user_id', 'to_status')
            ->get();

        /** @var array<int, array<string, int>> $byUser */
        $byUser = [];

        foreach ($transitions as $row) {
            $byUser[(int) $row->user_id][(string) $row->to_status] = (int) $row->aggregate;
        }

        $members = [];
        $totals = ['posts_created' => 0, 'transitions' => 0, 'sent_to_client' => 0, 'scheduled' => 0];

        foreach ($staff as $member) {
            $counts = $byUser[$member->id] ?? [];

            $row = [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role->value,
                'role_label' => $member->role->label(),
                'posts_created' => (int) ($created[$member->id] ?? 0),
                'transitions' => array_sum($counts),
                'sent_to_client' => $counts[PostStatus::WaitingClient->value] ?? 0,
                'scheduled' => $counts[PostStatus::Scheduled->value] ?? 0,
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }

            $members[] = $row;
        }

        return [
            'members' => $members,
            'client_decisions' => $this->clientDecisions($scope, $range),
            'totals' => $totals,
        ];
    }

    /**
     * Decisions on a post waiting for its client, made by the client (review link or portal).
     */
    private function clientDecisions(AnalyticsScope $scope, AnalyticsRange $range): int
    {
        return PostActivityLog::query()
            ->whereIn('post_id', $scope->posts()->select('posts.id'))
            ->whereBetween('created_at', $range->utcBounds())
            ->where('from_status', PostStatus::WaitingClient)
            ->whereIn('to_status', [PostStatus::Approved, PostStatus::ChangesRequested])
            ->where(fn ($query) => $query
                ->whereNull('user_id')
                ->orWhereIn('user_id', User::query()->where('role', Role::ClientReviewer)->select('id')))
            ->count();
    }
}
