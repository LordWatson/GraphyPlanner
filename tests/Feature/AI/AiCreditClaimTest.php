<?php

namespace Tests\Feature\AI;

use App\Enums\ImageVariantStatus;
use App\Enums\OrganizationType;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Exceptions\AI\AiImageQuotaExceededException;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use App\Services\AI\AiUsageGuard;
use App\Services\AI\Images\ImageQuotaGuard;
use App\Services\AI\MonthlyCreditCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI credits are *claimed* before they are spent, not counted after.
 *
 * The bug this guards against: both guards used to read the month's recorded usage and compare it
 * to the allowance. A call is only recorded once the vendor has answered, so every request that
 * started inside the same moment read the same low number and every one of them passed. An
 * organization with two image credits left could fan out nine renders and have all nine let
 * through — nine real charges at the vendor against two billed credits.
 */
class AiCreditClaimTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    // --- images -------------------------------------------------------------------------------

    public function test_concurrent_claims_cannot_all_pass_the_same_quota(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 2]);
        $guard = app(ImageQuotaGuard::class);

        // Ten callers, nothing recorded yet — exactly what a fan-out looks like.
        $allowed = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                $guard->assertCanGenerate($org, 1);
                $allowed++;
            } catch (AiImageQuotaExceededException) {
                // refused, as it should be
            }
        }

        $this->assertSame(2, $allowed, 'The quota is 2, so exactly two of ten claims may pass.');
    }

    public function test_a_claim_for_more_than_the_quota_is_refused_whole(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 4]);

        $this->expectException(AiImageQuotaExceededException::class);

        // Five at once against a quota of four: refused, and nothing part-claimed.
        app(ImageQuotaGuard::class)->assertCanGenerate($org, 5);
    }

    public function test_a_refused_claim_leaves_the_quota_intact(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 4]);
        $guard = app(ImageQuotaGuard::class);

        try {
            $guard->assertCanGenerate($org, 5);
        } catch (AiImageQuotaExceededException) {
        }

        // All four are still there — a refusal must not consume what it asked for.
        $guard->assertCanGenerate($org, 4);
        $this->addToAssertionCount(1);
    }

    public function test_an_unlimited_quota_never_refuses(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => null, 'plan' => 'agency_standard']);

        app(ImageQuotaGuard::class)->assertCanGenerate($org, 1_000);
        $this->addToAssertionCount(1);
    }

    public function test_a_failed_render_hands_its_credit_back(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 1]);
        $client = Client::factory()->for($org, 'organization')->create();
        $guard = app(ImageQuotaGuard::class);

        $guard->assertCanGenerate($org, 1);

        $generation = AiImageGeneration::factory()->for($client)->create(['org_id' => $org->id]);
        $variant = AiImageVariant::factory()->create([
            'generation_id' => $generation->id,
            'org_id' => $org->id,
            'status' => ImageVariantStatus::Generating,
        ]);

        // The one credit is spoken for.
        try {
            $guard->assertCanGenerate($org, 1);
            $this->fail('The quota of 1 was already claimed.');
        } catch (AiImageQuotaExceededException) {
        }

        // A failed render is never billed, so the claim goes back.
        $variant->update(['status' => ImageVariantStatus::Failed]);

        $guard->assertCanGenerate($org, 1);
        $this->addToAssertionCount(1);
    }

    public function test_marking_a_variant_failed_twice_only_releases_once(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 1]);
        $client = Client::factory()->for($org, 'organization')->create();
        $guard = app(ImageQuotaGuard::class);

        $guard->assertCanGenerate($org, 1);

        $generation = AiImageGeneration::factory()->for($client)->create(['org_id' => $org->id]);
        $variant = AiImageVariant::factory()->create([
            'generation_id' => $generation->id,
            'org_id' => $org->id,
            'status' => ImageVariantStatus::Generating,
        ]);

        $variant->update(['status' => ImageVariantStatus::Failed]);
        $variant->update(['status' => ImageVariantStatus::Failed, 'error_code' => null]);

        // One release, so one credit — not two.
        $guard->assertCanGenerate($org, 1);

        $this->expectException(AiImageQuotaExceededException::class);
        $guard->assertCanGenerate($org, 1);
    }

    public function test_the_counter_seeds_from_what_is_already_recorded(): void
    {
        $org = $this->org(['ai_monthly_image_quota' => 3]);
        $client = Client::factory()->for($org, 'organization')->create();
        $generation = AiImageGeneration::factory()->for($client)->create(['org_id' => $org->id]);

        // Three already rendered this month, and a cold cache (a flush, or a new worker).
        AiImageVariant::factory()->count(3)->create([
            'generation_id' => $generation->id,
            'org_id' => $org->id,
            'status' => ImageVariantStatus::Ready,
        ]);
        app(MonthlyCreditCounter::class)->forget(ImageQuotaGuard::BUCKET, $org->id);

        // A flushed cache must not hand out a free month.
        $this->expectException(AiImageQuotaExceededException::class);
        app(ImageQuotaGuard::class)->assertCanGenerate($org, 1);
    }

    // --- text ---------------------------------------------------------------------------------

    public function test_concurrent_text_claims_cannot_all_pass_the_same_allowance(): void
    {
        // `creator_pro` has a finite `ai_text_credits` allowance; read it rather than assume it.
        $org = $this->org(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $guard = app(AiUsageGuard::class);
        $allowance = $guard->textCreditAllowance($org);

        if ($allowance === null) {
            $this->markTestSkipped('The creator plan has an unlimited text allowance.');
        }

        $allowed = 0;

        for ($i = 0; $i < $allowance + 5; $i++) {
            try {
                $guard->ensureWithinBudget($org);
                $allowed++;
            } catch (AiBudgetExceededException) {
            }
        }

        $this->assertSame($allowance, $allowed);
    }

    public function test_a_text_claim_seeds_from_the_recorded_usage_log(): void
    {
        $org = $this->org(['type' => OrganizationType::Business, 'plan' => 'creator_pro']);
        $client = Client::factory()->for($org, 'organization')->primary()->create();
        $guard = app(AiUsageGuard::class);
        $allowance = $guard->textCreditAllowance($org);

        if ($allowance === null) {
            $this->markTestSkipped('The creator plan has an unlimited text allowance.');
        }

        for ($i = 0; $i < $allowance; $i++) {
            AiUsageLog::query()->create([
                'org_id' => $org->id,
                'client_id' => $client->id,
                'provider' => 'deepseek',
                'model' => 'test',
                'method' => 'captions',
                'succeeded' => true,
            ]);
        }

        app(MonthlyCreditCounter::class)->forget(AiUsageGuard::TEXT_BUCKET, $org->id);

        $this->expectException(AiBudgetExceededException::class);
        $guard->ensureWithinBudget($org);
    }
}
