<?php

namespace Database\Factories;

use App\Actions\Stories\SyncTargetStoryExtrasAction;
use App\Enums\Platform;
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

    /**
     * Step S-1.2: an Instagram Story channel (the format is only ever an explicit choice).
     */
    public function story(): static
    {
        return $this->state(fn () => [
            'social_account_id' => SocialAccount::factory()->state(['platform' => Platform::Instagram]),
            'format' => 'story',
        ]);
    }

    /**
     * Step S-1.2: Story extras on the channel, with `handoff_required` set the way
     * `SyncTargetStoryExtrasAction` would set it (the one writer of that column).
     *
     * @param  array<string, mixed>|null  $extras
     */
    public function withStoryExtras(?array $extras = null): static
    {
        return $this
            ->state(fn () => [
                'story_extras' => $extras ?? ['version' => 1, 'link' => ['url' => 'https://example.com/offer', 'text' => 'Shop now']],
            ])
            ->afterCreating(fn (PostTarget $target) => app(SyncTargetStoryExtrasAction::class)->refresh($target));
    }
}
