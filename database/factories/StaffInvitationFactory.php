<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'email' => $this->faker->safeEmail(),
            'role' => Role::Strategist,
            'invited_by_user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }
}
