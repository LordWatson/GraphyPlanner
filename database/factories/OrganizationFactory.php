<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'default_timezone' => 'UTC',
        ];
    }

    /**
     * Step 15.3: an organization a super admin has suspended.
     */
    public function suspended(?string $reason = 'Unpaid subscription'): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }
}
