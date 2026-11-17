<?php

namespace Database\Factories;

use App\Enums\StoryHandoffOutcome;
use App\Enums\StoryHandoffRecipient;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryHandoff>
 */
class StoryHandoffFactory extends Factory
{
    /**
     * Fill the org, client, post and attempt id from the channel, so a test only has to say which
     * channel the handoff is for.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (StoryHandoff $handoff) {
            $target = $handoff->post_target_id ? PostTarget::with('post')->find($handoff->post_target_id) : null;

            if ($target === null || $target->post === null) {
                return;
            }

            $handoff->post_id ??= $target->post_id;
            $handoff->org_id ??= $target->post->org_id;
            $handoff->client_id ??= $target->post->client_id;
            $handoff->publish_attempt_id ??= $target->publish_attempt_id ?? (string) Str::uuid();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'recipient_type' => StoryHandoffRecipient::Team,
            'due_at' => now(),
            'expires_at' => now()->addMinutes(120),
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'outcome' => StoryHandoffOutcome::Posted,
            'completed_at' => now(),
            'completed_via' => StoryHandoff::VIA_LINK,
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'outcome' => StoryHandoffOutcome::Missed,
            'completed_at' => now(),
        ]);
    }
}
