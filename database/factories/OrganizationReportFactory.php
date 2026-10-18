<?php

namespace Database\Factories;

use App\Enums\ReportSchedule;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationReport>
 */
class OrganizationReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => rtrim(fake()->sentence(3), '.'),
            'description' => null,
            'filters' => ['range' => '30d', 'from' => null, 'to' => null, 'client_ids' => [], 'campaign_ids' => [], 'platforms' => [], 'metrics' => []],
            'created_by' => fn (array $attributes) => User::factory()->state([
                'org_id' => $attributes['org_id'],
                'role' => Role::Owner,
            ]),
            'schedule' => null,
            'recipient_ids' => null,
        ];
    }

    /**
     * @param  list<int>  $recipientIds
     */
    public function scheduled(ReportSchedule $schedule = ReportSchedule::Weekly, array $recipientIds = []): static
    {
        return $this->state(['schedule' => $schedule, 'recipient_ids' => $recipientIds]);
    }
}
