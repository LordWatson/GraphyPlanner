<?php

namespace App\Http\Requests\Analytics;

use App\Enums\ReportSchedule;
use App\Enums\Role;
use App\Models\OrganizationReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 17.6 — create/update a saved organization report: a name, an optional note, the analytics
 * filters (same rules as the analytics page, nested under `filters.`) and, Step 17.8, an optional
 * email schedule with same-org staff recipients.
 */
class SaveOrganizationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $report instanceof OrganizationReport
            ? ($this->user()?->can('update', $report) ?? false)
            : ($this->user()?->can('create', OrganizationReport::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) $this->user()?->org_id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'filters' => ['required', 'array'],
            ...OrganizationAnalyticsFilterRequest::filterRules($orgId, 'filters.'),
            'schedule' => ['nullable', Rule::enum(ReportSchedule::class)],
            'recipient_ids' => ['nullable', 'array', 'max:50', 'required_with:schedule'],
            'recipient_ids.*' => [
                'integer',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereIn('role', [Role::Owner->value, Role::Strategist->value, Role::Designer->value, Role::Viewer->value]),
            ],
        ];
    }

    /**
     * The validated report, shaped for the create/update actions.
     *
     * @return array{name: string, description: string|null, filters: array<string, mixed>, schedule: string|null, recipient_ids: list<int>}
     */
    public function report(): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->validated('filters', []);

        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'filters' => $filters,
            'schedule' => $this->filled('schedule') ? $this->string('schedule')->toString() : null,
            'recipient_ids' => array_values(array_map('intval', (array) $this->validated('recipient_ids', []))),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'filters.range' => 'date range',
            'filters.from' => 'start date',
            'filters.to' => 'end date',
            'filters.client_ids' => 'clients',
            'filters.client_ids.*' => 'client',
            'filters.campaign_ids' => 'campaigns',
            'filters.campaign_ids.*' => 'campaign',
            'filters.platforms' => 'platforms',
            'filters.metrics' => 'sections',
            'recipient_ids' => 'recipients',
            'recipient_ids.*' => 'recipient',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filters.client_ids.*.exists' => 'That client isn’t in your studio.',
            'filters.campaign_ids.*.exists' => 'That campaign isn’t in your studio.',
            'recipient_ids.required_with' => 'Pick at least one person to send it to.',
            'recipient_ids.*.exists' => 'Recipients must be on your team.',
        ];
    }
}
