<?php

namespace App\Services\Security;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One org-wide, filterable record of who did what and when.
 *
 * The data already existed in four separate tables — staff role changes, post status transitions,
 * campaign events and plan changes — each readable only through the screen it belonged to. That is
 * useless for the question an auditor or a security questionnaire actually asks, which is "show me
 * everything this person did", across everything.
 *
 * Read-only by construction: this unions what other code has already written and never writes a
 * row of its own, so it cannot become a second, disagreeing version of the truth.
 *
 * **Why a union rather than one `audit_logs` table.** A single table would mean rewriting four
 * working, tested write paths and migrating their history, for a reporting view. The union is
 * confined to this class, the per-source mapping is explicit, and `AuditTrailTest` fails if a new
 * `*_activity_logs` or `*_audit_logs` table appears that this does not read — so the shortcut
 * cannot quietly rot.
 *
 * `shortcut: unions four tables in PHP and sorts in memory, so it reads a bounded window rather
 * than the whole history (MAX_ROWS). Fine for an export of a month or a quarter, which is what is
 * asked for. If anyone needs years at once, that is the point to introduce a real audit table and
 * backfill into it.`
 *
 * @phpstan-type AuditEntry array{at: string, source: string, action: string, actor: string|null, subject: string|null, detail: string|null}
 */
class AuditTrail
{
    /**
     * Rows read per source before merging. A deliberate ceiling: the alternative is an export that
     * can be asked to load an organization's entire history into memory.
     */
    public const MAX_ROWS = 5000;

    /**
     * Tables this reads, and which are deliberately not read. Checked by `AuditTrailTest`, so a new
     * log table cannot be added without a decision being recorded here.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'staff_activity_logs' => 'staff',
        'post_activity_logs' => 'post',
        'campaign_activity_logs' => 'campaign',
        'plan_audit_logs' => 'plan',
    ];

    /**
     * @var array<string, string>
     */
    public const NOT_AUDITED = [
        // Metering, not actions by people — it would bury the trail in thousands of rows.
        'ai_usage_logs' => 'usage metering, not an audit of actions',
        // The AI assistant's own calls, already its own surface in the MCP console.
        'mcp_call_logs' => 'has its own console view; add here if an auditor ever asks',
        // Content feedback on grids, which is collaboration rather than an auditable action.
        'grid_plan_activity_logs' => 'grid collaboration; not an access or permission event',
    ];

    /**
     * Everything that happened in the window, newest first.
     *
     * @return Collection<int, AuditEntry>
     */
    public function forOrganization(
        Organization $organization,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $actorUserId = null,
    ): Collection {
        return collect()
            ->merge($this->staff($organization, $from, $to, $actorUserId))
            ->merge($this->posts($organization, $from, $to, $actorUserId))
            ->merge($this->campaigns($organization, $from, $to, $actorUserId))
            ->merge($this->plan($organization, $from, $to, $actorUserId))
            ->sortByDesc('at')
            ->values();
    }

    /**
     * Role changes, invitations and removals — the access events an auditor asks about first.
     *
     * @return array<int, AuditEntry>
     */
    private function staff(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?int $actorUserId): array
    {
        $rows = DB::table('staff_activity_logs as l')
            ->leftJoin('users as actor', 'actor.id', '=', 'l.actor_user_id')
            ->leftJoin('users as subject', 'subject.id', '=', 'l.user_id')
            ->where('l.org_id', $organization->id)
            ->whereBetween('l.created_at', [$from, $to])
            ->when($actorUserId !== null, fn ($q) => $q->where('l.actor_user_id', $actorUserId))
            ->orderByDesc('l.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['l.created_at', 'l.action', 'l.from_role', 'l.to_role', 'l.note', 'actor.name as actor_name', 'subject.name as subject_name', 'subject.email as subject_email']);

        return $rows->map(fn (object $row): array => [
            'at' => (string) $row->created_at,
            'source' => 'staff',
            'action' => (string) $row->action,
            'actor' => $row->actor_name === null ? null : (string) $row->actor_name,
            'subject' => $row->subject_name === null ? null : (string) $row->subject_name.' <'.$row->subject_email.'>',
            'detail' => self::detail([
                $row->from_role === null ? null : 'from '.$row->from_role,
                $row->to_role === null ? null : 'to '.$row->to_role,
                $row->note === null ? null : (string) $row->note,
            ]),
        ])->all();
    }

    /**
     * Post status transitions: who approved, who requested changes, who scheduled.
     *
     * @return array<int, AuditEntry>
     */
    private function posts(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?int $actorUserId): array
    {
        $rows = DB::table('post_activity_logs as l')
            ->join('posts as p', 'p.id', '=', 'l.post_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'l.user_id')
            ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
            ->where('p.org_id', $organization->id)
            ->whereBetween('l.created_at', [$from, $to])
            ->when($actorUserId !== null, fn ($q) => $q->where('l.user_id', $actorUserId))
            ->orderByDesc('l.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['l.created_at', 'l.from_status', 'l.to_status', 'l.note', 'l.post_id', 'actor.name as actor_name', 'c.name as client_name']);

        return $rows->map(fn (object $row): array => [
            'at' => (string) $row->created_at,
            'source' => 'post',
            'action' => 'status.'.$row->to_status,
            'actor' => $row->actor_name === null ? null : (string) $row->actor_name,
            'subject' => 'Post #'.$row->post_id.($row->client_name === null ? '' : ' ('.$row->client_name.')'),
            'detail' => self::detail([
                $row->from_status === null ? null : 'from '.$row->from_status,
                $row->note === null ? null : (string) $row->note,
            ]),
        ])->all();
    }

    /**
     * @return array<int, AuditEntry>
     */
    private function campaigns(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?int $actorUserId): array
    {
        $rows = DB::table('campaign_activity_logs as l')
            ->join('campaigns as cm', 'cm.id', '=', 'l.campaign_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'l.user_id')
            ->where('cm.org_id', $organization->id)
            ->whereBetween('l.created_at', [$from, $to])
            ->when($actorUserId !== null, fn ($q) => $q->where('l.user_id', $actorUserId))
            ->orderByDesc('l.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['l.created_at', 'l.event', 'l.from', 'l.to', 'cm.name as campaign_name', 'actor.name as actor_name']);

        return $rows->map(fn (object $row): array => [
            'at' => (string) $row->created_at,
            'source' => 'campaign',
            'action' => (string) $row->event,
            'actor' => $row->actor_name === null ? null : (string) $row->actor_name,
            'subject' => (string) $row->campaign_name,
            'detail' => self::detail([
                $row->from === null ? null : 'from '.$row->from,
                $row->to === null ? null : 'to '.$row->to,
            ]),
        ])->all();
    }

    /**
     * Plan and billing changes — who upgraded, who was moved, and by whom.
     *
     * @return array<int, AuditEntry>
     */
    private function plan(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?int $actorUserId): array
    {
        $rows = DB::table('plan_audit_logs as l')
            ->leftJoin('users as actor', 'actor.id', '=', 'l.actor_user_id')
            ->where('l.org_id', $organization->id)
            ->whereBetween('l.created_at', [$from, $to])
            ->when($actorUserId !== null, fn ($q) => $q->where('l.actor_user_id', $actorUserId))
            ->orderByDesc('l.created_at')
            ->limit(self::MAX_ROWS)
            ->get(['l.created_at', 'l.action', 'l.from_plan', 'l.to_plan', 'l.note', 'actor.name as actor_name']);

        return $rows->map(fn (object $row): array => [
            'at' => (string) $row->created_at,
            'source' => 'plan',
            'action' => (string) $row->action,
            'actor' => $row->actor_name === null ? null : (string) $row->actor_name,
            'subject' => null,
            'detail' => self::detail([
                $row->from_plan === null ? null : 'from '.$row->from_plan,
                $row->to_plan === null ? null : 'to '.$row->to_plan,
                $row->note === null ? null : (string) $row->note,
            ]),
        ])->all();
    }

    /**
     * @param  list<string|null>  $parts
     */
    private static function detail(array $parts): ?string
    {
        $filtered = array_values(array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));

        return $filtered === [] ? null : implode(' · ', $filtered);
    }
}
