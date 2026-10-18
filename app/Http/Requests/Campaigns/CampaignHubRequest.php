<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignObjective;
use App\Enums\CampaignStatus;
use App\Enums\ClientHealth;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignTimeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step C-1.1 — the campaigns hub's query string. Ids that don't belong to the viewer's
 * organization simply match nothing (the list query is org-scoped).
 */
class CampaignHubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewHub', Campaign::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(['list', 'timeline'])],
            'search' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'timeline' => ['nullable', Rule::in([CampaignTimeline::UPCOMING, CampaignTimeline::LIVE, CampaignTimeline::ENDED, CampaignTimeline::UNDATED])],
            'owner' => ['nullable', 'integer'],
            'objective' => ['nullable', Rule::enum(CampaignObjective::class)],
            'health' => ['nullable', Rule::enum(ClientHealth::class)],
            'sort' => ['nullable', Rule::in(['start_asc', 'start_desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function view(): string
    {
        return $this->string('view')->toString() === 'timeline' ? 'timeline' : 'list';
    }

    /**
     * @return array{search: string|null, client_id: int|null, status: string|null, timeline: string|null, owner_id: int|null, objective: string|null, health: string|null, sort: string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->string('search')->trim()->toString() ?: null,
            'client_id' => $this->integer('client') ?: null,
            'status' => $this->string('status')->toString() ?: null,
            'timeline' => $this->string('timeline')->toString() ?: null,
            'owner_id' => $this->integer('owner') ?: null,
            'objective' => $this->string('objective')->toString() ?: null,
            'health' => $this->string('health')->toString() ?: null,
            'sort' => $this->string('sort')->toString() ?: 'start_asc',
        ];
    }
}
