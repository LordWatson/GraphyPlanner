<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SavedPostView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedPostView>
 */
class SavedPostViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'filters' => ['status' => 'draft'],
        ];
    }
}
