<?php

namespace App\Actions\Campaigns;

use App\Enums\BriefStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-3.4 — "New campaign from template": pick a client, a start date and (optionally) which of
 * the template's platforms to keep. The campaign, its phases, its deliverables and a draft brief
 * (with `{client_name}` filled in) are created relative to the start date, in one transaction.
 */
class CreateCampaignFromTemplateAction
{
    public function __construct(private readonly CreateCampaignAction $createCampaign) {}

    /**
     * @param  list<string>|null  $platforms  keep only these platforms' deliverables (null = all)
     */
    public function __invoke(CampaignTemplate $template, Client $client, CarbonImmutable $start, string $name, ?array $platforms, User $user): Campaign
    {
        $campaign = DB::transaction(function () use ($template, $client, $start, $name, $platforms, $user) {
            $deliverables = array_values(array_filter(
                $template->deliverables ?? [],
                fn (array $item) => $platforms === null || in_array($item['platform'], $platforms, true),
            ));

            $campaign = ($this->createCampaign)($client, [
                'name' => $name,
                'status' => CampaignStatus::Planning,
                'objective' => $template->objective,
                'start_date' => $start->toDateString(),
                'end_date' => $start->addDays(max(1, $template->duration_days) - 1)->toDateString(),
                'platforms' => array_values(array_unique(array_column($deliverables, 'platform'))),
            ], $user);

            $phaseIds = [];

            foreach ($template->phases ?? [] as $index => $phase) {
                $phaseStart = $start->addDays((int) $phase['start_offset']);
                $phaseIds[$index] = $campaign->phases()->create([
                    'name' => $phase['name'],
                    'starts_on' => $phaseStart->toDateString(),
                    'ends_on' => $phaseStart->addDays(max(1, (int) $phase['length']) - 1)->toDateString(),
                    'colour' => $phase['colour'] ?? null,
                    'sort' => $index + 1,
                ])->id;
            }

            foreach ($deliverables as $sort => $item) {
                $campaign->deliverables()->create([
                    'campaign_phase_id' => isset($item['phase_index']) ? ($phaseIds[$item['phase_index']] ?? null) : null,
                    'title' => $this->fill((string) $item['title'], $client),
                    'platform' => $item['platform'],
                    'format' => $item['format'] ?? null,
                    'pillar' => $item['pillar'] ?? null,
                    'planned_for' => $start->addDays((int) $item['day_offset'])->toDateString(),
                    'planned_time' => $item['time'] ?? null,
                    'sort' => $sort + 1,
                ]);
            }

            if (! empty($template->brief)) {
                $brief = [];

                foreach ($template->brief as $field => $value) {
                    $brief[$field] = is_array($value)
                        ? array_map(fn ($line) => $this->fill((string) $line, $client), $value)
                        : ($value === null ? null : $this->fill((string) $value, $client));
                }

                $campaign->brief()->create([...$brief, 'status' => BriefStatus::Draft, 'updated_by' => $user->id]);
            }

            $campaign->recordActivity('campaign.from_template', $user, meta: ['template_id' => $template->id]);

            return $campaign;
        });

        Log::info('Campaign created from template', [
            'campaign_id' => $campaign->id,
            'template_id' => $template->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
        ]);

        return $campaign;
    }

    private function fill(string $text, Client $client): string
    {
        return str_replace('{client_name}', (string) $client->name, $text);
    }
}
