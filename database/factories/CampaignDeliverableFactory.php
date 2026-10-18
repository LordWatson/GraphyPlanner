<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignDeliverable>
 */
class CampaignDeliverableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'campaign_phase_id' => null,
            'title' => fake()->sentence(4),
            'platform' => Platform::Instagram,
            'format' => null,
            'pillar' => null,
            'planned_for' => now()->addDays(10)->toDateString(),
            'planned_time' => '09:00',
            'assignee_id' => null,
            'notes' => null,
            'asset_requirements' => null,
            'post_id' => null,
            'sort' => 0,
        ];
    }
}
