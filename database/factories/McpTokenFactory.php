<?php

namespace Database\Factories;

use App\Models\McpToken;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => null,
            'name' => fake()->words(2, true).' connection',
            'token_hash' => McpToken::hashToken(Str::random(64)),
            'scopes' => ['read'],
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subMinute()]);
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }
}
