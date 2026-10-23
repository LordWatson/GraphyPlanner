<?php

namespace Database\Factories;

use App\Models\PortalLoginToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PortalLoginToken>
 */
class PortalLoginTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => PortalLoginToken::hashToken(Str::random(64)),
            'post_id' => null,
            'expires_at' => now()->addDays(7),
            'used_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function used(): static
    {
        return $this->state(['used_at' => now()->subMinute()]);
    }
}
