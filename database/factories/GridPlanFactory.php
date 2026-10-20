<?php

namespace Database\Factories;

use App\Enums\GridPlanStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GridPlan>
 */
class GridPlanFactory extends Factory
{
    /**
     * Fill `org_id` from the client, and give the plan an Instagram account of that client when
     * none was set.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (GridPlan $plan) {
            $client = $plan->client_id ? Client::query()->withoutGlobalScopes()->find($plan->client_id) : null;

            if (! $plan->org_id && $client !== null) {
                $plan->org_id = $client->org_id;
            }

            if (! $plan->social_account_id && $client !== null) {
                $plan->social_account_id = SocialAccount::factory()->create([
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'platform' => Platform::Instagram,
                    'timezone' => 'Europe/London',
                ])->id;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->randomElement(['Spring launch grid', 'November feed', 'Holiday grid']),
            'status' => GridPlanStatus::Draft,
            'pinned_count' => 0,
            'include_live_feed' => true,
            'version' => 1,
        ];
    }

    public function status(GridPlanStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
