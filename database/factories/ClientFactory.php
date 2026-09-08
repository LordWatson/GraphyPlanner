<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->unique()->company(),
            'legal_name' => fake()->company().' LLC',
            'website' => fake()->url(),
            'industry' => fake()->word(),
            'countries' => [fake()->countryCode()],
            'status' => ClientStatus::Active,
            'owner_user_id' => null,
            'start_date' => fake()->date(),
            'retainer_amount' => fake()->randomFloat(2, 500, 10000),
            'billing_cycle' => BillingCycle::Monthly,
            'tags' => [],
            'default_language' => 'en',
            'notes_internal' => null,
            'approval_email' => fake()->companyEmail(),
        ];
    }
}
