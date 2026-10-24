<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ProductFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFeedback>
 */
class ProductFeedbackFactory extends Factory
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
            'user_id' => User::factory(),
            'page' => '/'.fake()->randomElement(['dashboard', 'calendar', 'clients', 'posts']),
            'message' => fake()->sentence(12),
        ];
    }
}
