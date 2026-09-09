<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ReviewToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewToken>
 */
class ReviewTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', Str::random(40)),
            'client_id' => Client::factory(),
            'post_id' => null,
            'expires_at' => now()->addDays(7),
            'revoked_at' => null,
        ];
    }
}
