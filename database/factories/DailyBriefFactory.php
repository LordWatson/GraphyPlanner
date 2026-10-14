<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\DailyBrief;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyBrief>
 */
class DailyBriefFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = Client::factory()->create();

        return [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'brief_date' => fake()->date(),
            'trends' => [
                ['title' => fake()->sentence(3), 'summary' => fake()->sentence(), 'platforms' => ['instagram'], 'confidence' => 0.7],
            ],
            'ideas' => [
                ['title' => fake()->sentence(3), 'description' => fake()->sentence(), 'platforms' => ['instagram'], 'suggestedCaption' => fake()->sentence(), 'suggestedPostAt' => null],
            ],
            'notified_at' => null,
        ];
    }
}
