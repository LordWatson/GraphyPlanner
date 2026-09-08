<?php

namespace Database\Factories;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Post $post) {
            if (! $post->org_id && $post->client_id) {
                $post->org_id = Client::find($post->client_id)?->org_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'status' => PostStatus::Draft,
            'approval_mode' => ApprovalMode::ClientRequired,
            'master_caption' => fake()->sentence(),
            'hashtags' => [],
            'music' => null,
            'location' => null,
            'checklist_snapshot' => null,
        ];
    }
}
