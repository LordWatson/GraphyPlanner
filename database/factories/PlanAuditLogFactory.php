<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PlanAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanAuditLog>
 */
class PlanAuditLogFactory extends Factory
{
    protected $model = PlanAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'actor_user_id' => null,
            'action' => PlanAuditLog::ACTION_PLAN_CHANGED,
            'from_plan' => 'business_free',
            'to_plan' => 'business_pro',
            'note' => null,
            'meta' => null,
        ];
    }
}
