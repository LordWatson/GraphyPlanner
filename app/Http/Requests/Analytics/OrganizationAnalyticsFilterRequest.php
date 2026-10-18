<?php

namespace App\Http\Requests\Analytics;

use App\Enums\Platform;
use App\Models\Organization;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 17.2 — the organization analytics page's (and client drill-down's and exports') query-string
 * filters. Client and campaign ids must belong to the viewer's own organization: a foreign id is a
 * validation error, never an empty section that would confirm it exists.
 */
class OrganizationAnalyticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->organization();

        return $organization !== null && ($this->user()?->can('viewAnalytics', $organization) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::filterRules((int) $this->user()?->org_id);
    }

    /**
     * Shared with `SaveOrganizationReportRequest`, which nests the same rules under `filters.`.
     *
     * @return array<string, mixed>
     */
    public static function filterRules(int $orgId, string $prefix = ''): array
    {
        return [
            "{$prefix}range" => ['nullable', Rule::in(AnalyticsRange::PRESETS)],
            "{$prefix}from" => ["required_if:{$prefix}range,custom", 'nullable', 'date_format:Y-m-d'],
            "{$prefix}to" => ["required_if:{$prefix}range,custom", 'nullable', 'date_format:Y-m-d', "after_or_equal:{$prefix}from"],
            "{$prefix}client_ids" => ['nullable', 'array', 'max:100'],
            "{$prefix}client_ids.*" => ['integer', Rule::exists('clients', 'id')->where('org_id', $orgId)],
            "{$prefix}campaign_ids" => ['nullable', 'array', 'max:100'],
            "{$prefix}campaign_ids.*" => ['integer', Rule::exists('campaigns', 'id')->where('org_id', $orgId)],
            "{$prefix}platforms" => ['nullable', 'array'],
            "{$prefix}platforms.*" => ['string', Rule::enum(Platform::class)],
            "{$prefix}metrics" => ['nullable', 'array'],
            "{$prefix}metrics.*" => ['string', Rule::in(OrganizationAnalyticsFilters::METRICS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_ids.*.exists' => 'That client isn’t in your studio.',
            'campaign_ids.*.exists' => 'That campaign isn’t in your studio.',
        ];
    }

    public function organization(): ?Organization
    {
        return $this->user()?->organization;
    }

    public function filters(): OrganizationAnalyticsFilters
    {
        /** @var Organization $organization */
        $organization = $this->organization();

        return OrganizationAnalyticsFilters::fromArray($this->validated(), $organization);
    }
}
