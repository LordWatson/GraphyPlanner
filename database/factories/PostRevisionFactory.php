<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Post;
use App\Models\PostRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostRevision>
 */
class PostRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'post_id' => Post::factory(),
            'created_by' => null,
            'snapshot' => ['master_caption' => fake()->sentence(), 'hashtags' => [], 'targets' => [], 'assets' => []],
            'shared_at' => null,
        ];
    }
}
