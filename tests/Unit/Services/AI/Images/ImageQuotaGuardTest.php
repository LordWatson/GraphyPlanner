<?php

namespace Tests\Unit\Services\AI\Images;

use App\Enums\ImageVariantStatus;
use App\Exceptions\AI\AiImageQuotaExceededException;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\Client;
use App\Models\Organization;
use App\Services\AI\Images\ImageQuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 16.11: the monthly image quota (under / at / over, month rollover, failures not counted).
 */
class ImageQuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    private function variants(Organization $org, int $count, array $attributes = []): void
    {
        $client = Client::factory()->for($org, 'organization')->create();
        $generation = AiImageGeneration::factory()->create(['client_id' => $client->id, 'org_id' => $org->id]);

        AiImageVariant::factory()->count($count)->sequence(fn ($sequence) => ['position' => $sequence->index])->create([
            'generation_id' => $generation->id,
            'org_id' => $org->id,
            'status' => ImageVariantStatus::Ready,
            ...$attributes,
        ]);
    }

    public function test_unlimited_when_no_quota_is_set(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => null]);
        $this->variants($org, 5);

        (new ImageQuotaGuard)->assertCanGenerate($org, 100);

        $this->assertSame(['used' => 5, 'quota' => null, 'remaining' => null], (new ImageQuotaGuard)->summary($org));
    }

    public function test_allows_up_to_and_including_the_quota(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => 6]);
        $this->variants($org, 2);

        (new ImageQuotaGuard)->assertCanGenerate($org, 4);

        $this->assertSame(4, (new ImageQuotaGuard)->summary($org)['remaining']);
    }

    public function test_rejects_over_the_quota(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => 6]);
        $this->variants($org, 3);

        $this->expectException(AiImageQuotaExceededException::class);

        (new ImageQuotaGuard)->assertCanGenerate($org, 4);
    }

    public function test_failed_images_do_not_count(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => 4]);
        $this->variants($org, 4, ['status' => ImageVariantStatus::Failed]);

        (new ImageQuotaGuard)->assertCanGenerate($org, 4);
        $this->assertSame(0, (new ImageQuotaGuard)->usedThisMonth($org));
    }

    public function test_last_months_images_roll_over(): void
    {
        $org = Organization::factory()->create(['ai_monthly_image_quota' => 4]);
        $this->variants($org, 4, ['created_at' => CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $this->assertSame(0, (new ImageQuotaGuard)->usedThisMonth($org));
        (new ImageQuotaGuard)->assertCanGenerate($org, 4);

        $this->assertSame(4, (new ImageQuotaGuard)->usedThisMonth($org, CarbonImmutable::now()->subMonthNoOverflow()));
    }

    public function test_the_unique_idempotency_key_is_enforced(): void
    {
        $org = Organization::factory()->create();
        $this->variants($org, 1, ['idempotency_key' => 'same-key']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->variants($org, 1, ['idempotency_key' => 'same-key']);
    }
}
