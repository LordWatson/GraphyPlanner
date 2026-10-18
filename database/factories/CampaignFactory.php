<?php

namespace Database\Factories;

use App\Enums\CampaignColour;
use App\Enums\CampaignObjective;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Campaign $campaign) {
            if (! $campaign->org_id && $campaign->client_id) {
                $campaign->org_id = Client::query()->withoutOrgScope()->find($campaign->client_id)?->org_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->catchPhrase(),
            'status' => CampaignStatus::Planning,
            'start_date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'end_date' => null,
            'goal' => fake()->sentence(),
            'notes' => null,
        ];
    }

    public function status(CampaignStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function planning(): static
    {
        return $this->status(CampaignStatus::Planning);
    }

    public function active(): static
    {
        return $this->status(CampaignStatus::Active);
    }

    public function paused(): static
    {
        return $this->status(CampaignStatus::Paused);
    }

    public function completed(): static
    {
        return $this->status(CampaignStatus::Completed);
    }

    public function archived(): static
    {
        return $this->status(CampaignStatus::Archived);
    }

    public function objective(CampaignObjective $objective): static
    {
        return $this->state(['objective' => $objective]);
    }

    public function colour(CampaignColour $colour): static
    {
        return $this->state(['colour' => $colour]);
    }

    /**
     * Dates relative to today: `dated(-3, 10)` started 3 days ago and ends in 10 days.
     */
    public function dated(int $startOffsetDays, ?int $endOffsetDays = null): static
    {
        return $this->state([
            'start_date' => now()->addDays($startOffsetDays)->toDateString(),
            'end_date' => $endOffsetDays === null ? null : now()->addDays($endOffsetDays)->toDateString(),
        ]);
    }

    public function undated(): static
    {
        return $this->state(['start_date' => null, 'end_date' => null]);
    }
}
