<?php

namespace Database\Factories;

use App\Enums\ApprovalDecision;
use App\Models\Post;
use App\Models\PostApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostApproval>
 */
class PostApprovalFactory extends Factory
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
            'user_id' => User::factory(),
            'decision' => ApprovalDecision::Approved,
            'comment' => null,
        ];
    }
}
