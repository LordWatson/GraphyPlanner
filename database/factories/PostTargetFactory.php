<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTarget>
 */
class PostTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'scheduled_local_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'scheduled_local_time' => '09:00:00',
            'scheduled_at_utc' => null,
        ];
    }
}
