<?php

namespace App\Support\Analytics;

use App\Enums\ReportSchedule;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

/**
 * Step 17.6/17.8 — normalises a validated report payload into the columns both
 * `CreateOrganizationReportAction` and `UpdateOrganizationReportAction` write, so what's stored is
 * exactly what `RunOrganizationReportAction` reads back: filters through
 * `OrganizationAnalyticsFilters` (foreign/deleted ids dropped, presets kept relative) and
 * recipients limited to the organization's own staff.
 */
final class OrganizationReportAttributes
{
    /**
     * @param  array{name: string, description?: string|null, filters: array<string, mixed>, schedule?: string|null, recipient_ids?: list<int>}  $data
     * @return array{name: string, description: string|null, filters: array<string, mixed>, schedule: ReportSchedule|null, recipient_ids: list<int>|null}
     */
    public static function attributes(Organization $organization, array $data): array
    {
        $schedule = ReportSchedule::tryFrom((string) ($data['schedule'] ?? ''));
        $recipients = $schedule === null ? [] : self::recipients($organization, $data['recipient_ids'] ?? []);

        return [
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'filters' => OrganizationAnalyticsFilters::fromArray($data['filters'], $organization)->toStoredArray(),
            // A schedule with nobody to send to is no schedule.
            'schedule' => $recipients === [] ? null : $schedule,
            'recipient_ids' => $recipients === [] ? null : $recipients,
        ];
    }

    /**
     * Same-org staff only (never client reviewers), in id order.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    public static function recipients(Organization $organization, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        if ($ids === []) {
            return [];
        }

        return array_values(array_map('intval', User::query()
            ->where('org_id', $organization->id)
            ->whereIn('id', $ids)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }
}
