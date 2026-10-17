<?php

namespace App\Http\Requests\SuperAdmin;

use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 15.4 — the analytics page's (and per-org drill-down's) query-string filters.
 */
class AnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::filterRules();
    }

    /**
     * Shared with `SaveReportRequest`, which nests the same rules under `filters.`.
     *
     * @return array<string, mixed>
     */
    public static function filterRules(string $prefix = ''): array
    {
        return [
            "{$prefix}range" => ['nullable', Rule::in(AnalyticsFilters::RANGES)],
            "{$prefix}from" => ["required_if:{$prefix}range,custom", 'nullable', 'date_format:Y-m-d'],
            "{$prefix}to" => ["required_if:{$prefix}range,custom", 'nullable', 'date_format:Y-m-d', "after_or_equal:{$prefix}from"],
            "{$prefix}org_ids" => ['nullable', 'array', 'max:100'],
            "{$prefix}org_ids.*" => ['integer', Rule::exists('organizations', 'id')],
            "{$prefix}metrics" => ['nullable', 'array'],
            "{$prefix}metrics.*" => ['string', Rule::in(AnalyticsFilters::METRICS)],
        ];
    }

    public function filters(): AnalyticsFilters
    {
        return AnalyticsFilters::fromArray($this->validated());
    }
}
