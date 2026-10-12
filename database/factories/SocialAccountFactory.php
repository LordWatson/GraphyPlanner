<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (SocialAccount $socialAccount) {
            if (! $socialAccount->org_id && $socialAccount->client_id) {
                $socialAccount->org_id = Client::find($socialAccount->client_id)?->org_id;
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
            'platform' => fake()->randomElement(Platform::cases()),
            'handle' => '@'.fake()->userName(),
            'display_name' => fake()->company(),
            'timezone' => fake()->randomElement(['America/New_York', 'Europe/London', 'Asia/Tokyo']),
            'language' => 'en',
            'country' => fake()->countryCode(),
            'default_location' => null,
            'posting_windows' => null,
            'persona_override' => null,
            'connection_status' => ConnectionStatus::NotConnected,
        ];
    }

    /**
     * Indicate that the account is connected to the vendor.
     */
    public function connected(): static
    {
        return $this->state(fn () => [
            'connection_status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]);
    }

    /**
     * Indicate the account has completed the additive Facebook Login connect flow (Step 1.9.3),
     * granting the Meta Graph API access needed for Instagram audio search (Step 1.9.4).
     */
    public function metaConnected(): static
    {
        return $this->state(fn () => [
            'platform' => Platform::Instagram,
            'meta_access_token' => fake()->sha256(),
            'meta_access_token_expires_at' => now()->addDays(60),
            'meta_instagram_user_id' => (string) fake()->numberBetween(100000000000000, 999999999999999),
        ]);
    }
}
