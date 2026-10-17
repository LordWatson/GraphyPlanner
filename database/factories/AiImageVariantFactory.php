<?php

namespace Database\Factories;

use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiImageVariant>
 */
class AiImageVariantFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AiImageVariant $variant) {
            if (! $variant->org_id && $variant->generation_id) {
                $variant->org_id = (int) AiImageGeneration::find($variant->generation_id)?->org_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => AiImageGeneration::factory(),
            'position' => 0,
            'kind' => ImageVariantKind::Generate,
            'status' => ImageVariantStatus::Queued,
            'quality_tier' => ImageQualityTier::Draft,
            'idempotency_key' => (string) Str::uuid(),
            'seed' => fake()->numberBetween(1, 100000),
            'cost_estimate' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => ['status' => ImageVariantStatus::Ready]);
    }
}
