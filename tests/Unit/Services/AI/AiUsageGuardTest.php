<?php

namespace Tests\Unit\Services\AI;

use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\AI\AiUsageGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-7.1 (dev plan Step 2.8) — the per-org monthly token allowance.
 */
class AiUsageGuardTest extends TestCase
{
    use RefreshDatabase;

    private AiUsageGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new AiUsageGuard;
    }

    private function logTokens(Organization $org, int $prompt, int $completion, ?CarbonImmutable $at = null): void
    {
        $log = AiUsageLog::create([
            'org_id' => $org->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-flash',
            'method' => 'suggestCaptions',
            'succeeded' => true,
            'cost_estimate' => 0.002,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
        ]);

        if ($at !== null) {
            $log->forceFill(['created_at' => $at])->saveQuietly();
        }
    }

    public function test_a_null_budget_is_unlimited(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => null]);
        $this->logTokens($org, 10000, 5000);

        $this->guard->ensureWithinBudget($org);
        $this->assertTrue(true); // no exception
    }

    public function test_under_budget_passes_and_at_or_over_throws(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => 1000]);

        // Under budget.
        $this->logTokens($org, 400, 300); // 700
        $this->guard->ensureWithinBudget($org);

        // Push to exactly the ceiling → next call is refused.
        $this->logTokens($org, 200, 100); // total 1000
        $this->expectException(AiBudgetExceededException::class);
        $this->guard->ensureWithinBudget($org);
    }

    public function test_only_this_calendar_month_counts(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => 1000]);

        // Last month's usage doesn't count against this month.
        $this->logTokens($org, 900, 900, CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->assertSame(0, $this->guard->tokensUsedThisMonth($org));
        $this->guard->ensureWithinBudget($org);
    }

    public function test_summary_reports_text_usage_only(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => 5000]);
        $this->logTokens($org, 1000, 500); // 1500 text tokens

        // An image row must not count toward the text summary.
        AiUsageLog::create([
            'org_id' => $org->id, 'provider' => 'gemini', 'model' => 'x', 'method' => 'image.generate',
            'succeeded' => true, 'cost_estimate' => 0.067,
        ]);

        $summary = $this->guard->summary($org);

        $this->assertSame(5000, $summary['budget']);
        $this->assertSame(1500, $summary['used']);
        $this->assertSame(3500, $summary['remaining']);
        $this->assertSame(1, $summary['requests']);
    }
}
