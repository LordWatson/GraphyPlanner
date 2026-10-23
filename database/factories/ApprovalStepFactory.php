<?php

namespace Database\Factories;

use App\Models\ApprovalStep;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalStep>
 */
class ApprovalStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $post = Post::factory()->create();

        return [
            'org_id' => $post->org_id,
            'post_id' => $post->id,
            'client_contact_id' => null,
            'position' => 0,
            'decision' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'comment' => null,
            'approved_revision_id' => null,
        ];
    }
}
