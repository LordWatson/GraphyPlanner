<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\SuperAdminReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 15.5 — create/update a saved report: a name plus the analytics filters (same rules as the
 * analytics page, nested under `filters.`).
 */
class SaveReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $report instanceof SuperAdminReport
            ? ($this->user()?->can('update', $report) ?? false)
            : ($this->user()?->can('create', SuperAdminReport::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'filters' => ['required', 'array'],
            ...AnalyticsFilterRequest::filterRules('filters.'),
        ];
    }

    /**
     * The validated report, shaped for `CreateReportAction` / `UpdateReportAction`.
     *
     * @return array{name: string, description: string|null, filters: array<string, mixed>}
     */
    public function report(): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->validated('filters', []);

        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'filters' => $filters,
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
            'filters.org_ids' => 'organizations',
            'filters.metrics' => 'metrics',
        ];
    }
}
