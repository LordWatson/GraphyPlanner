<?php

namespace Tests\Unit\Services\AI;

use App\Enums\OrganizationType;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\AI\AiUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step CG-2.3 / B-9.2 — the plan's monthly AI text-credit allowance is enforced by the usage guard,
 * including an unexpired super-admin bonus grant. Agency plans (unlimited) are never blocked.
 */
class AiTextCreditAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plans.plans.business_free.limits.ai_text_credits' => 2]);
    }

    private function logTextCalls(Organization $org, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiUsageLog::create([
                'org_id' => $org->id,
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'method' => 'suggest_captions',
                'succeeded' => true,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ]);
        }
    }

    public function test_it_blocks_once_the_plan_text_credits_are_used(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
        $this->logTextCalls($org, 2);

        $this->expectException(AiBudgetExceededException::class);

        (new AiUsageGuard)->ensureWithinBudget($org);
    }

    public function test_it_allows_while_under_the_allowance(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Business, 'plan' => 'business_free']);
        $this->logTextCalls($org, 1);

        (new AiUsageGuard)->ensureWithinBudget($org);
        $this->addToAssertionCount(1);
    }

    public function test_a_bonus_grant_extends_the_allowance(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'business_free',
            'bonus_ai_text_credits' => 3,
            'bonus_ai_credits_expires_at' => now()->addDays(7),
        ]);
        $this->logTextCalls($org, 4); // 2 base + 3 bonus = 5 allowed; 4 is still under

        (new AiUsageGuard)->ensureWithinBudget($org);
        $this->addToAssertionCount(1);
    }

    public function test_an_expired_bonus_grant_does_not_count(): void
    {
        $org = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'plan' => 'business_free',
            'bonus_ai_text_credits' => 3,
            'bonus_ai_credits_expires_at' => now()->subDay(),
        ]);
        $this->logTextCalls($org, 2);

        $this->expectException(AiBudgetExceededException::class);

        (new AiUsageGuard)->ensureWithinBudget($org);
    }

    public function test_agency_plans_are_unlimited(): void
    {
        $org = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => null]);
        $this->logTextCalls($org, 50);

        (new AiUsageGuard)->ensureWithinBudget($org);
        $this->addToAssertionCount(1);
    }
}
