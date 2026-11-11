<?php

namespace Database\Factories;

use App\Enums\RegistrationInterestStatus;
use App\Models\RegistrationInterest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationInterest>
 */
class RegistrationInterestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'company_name' => fake()->company(),
            'location' => null,
            'status' => RegistrationInterestStatus::New,
            'ip_address' => fake()->ipv4(),
        ];
    }
}
