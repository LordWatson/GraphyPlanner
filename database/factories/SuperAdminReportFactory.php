<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\SuperAdminReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuperAdminReport>
 */
class SuperAdminReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'description' => null,
            'filters' => ['range' => '30d', 'org_ids' => [], 'metrics' => []],
            'created_by' => User::factory()->state(['org_id' => null, 'role' => Role::SuperAdmin]),
        ];
    }
}
