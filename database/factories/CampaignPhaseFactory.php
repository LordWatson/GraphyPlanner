<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignPhase>
 */
class CampaignPhaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => fake()->randomElement(['Teaser', 'Launch', 'Sustain', 'Wrap']),
            'starts_on' => null,
            'ends_on' => null,
            'sort' => 0,
            'colour' => null,
        ];
    }
}
