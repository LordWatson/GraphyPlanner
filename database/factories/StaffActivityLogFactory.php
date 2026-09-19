<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\StaffActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffActivityLog>
 */
class StaffActivityLogFactory extends Factory
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
            'user_id' => User::factory(),
            'actor_user_id' => User::factory(),
            'action' => 'role_changed',
            'from_role' => Role::Viewer,
            'to_role' => Role::Strategist,
            'note' => null,
        ];
    }
}
