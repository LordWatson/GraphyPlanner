<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\SavedReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedReply>
 */
class SavedReplyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = Client::factory()->create();

        return [
            'org_id' => $client->org_id,
            'client_id' => null,
            'created_by' => null,
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(),
            'shortcut' => null,
            'usage_count' => 0,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(['client_id' => $client->id, 'org_id' => $client->org_id]);
    }
}
