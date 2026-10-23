<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
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
            'type' => OrganizationType::Agency,
            'default_timezone' => 'UTC',
        ];
    }

    /**
     * Step B-1.1 — a one-brand business/creator workspace. Pair with `Client::factory()->primary()`
     * (or `CreatePrimaryBrandAction`) to give it its single brand.
     */
    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => OrganizationType::Business,
        ]);
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
