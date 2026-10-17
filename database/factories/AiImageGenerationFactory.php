<?php

namespace Database\Factories;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageGenerationStatus;
use App\Enums\ImageQualityTier;
use App\Models\AiImageGeneration;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiImageGeneration>
 */
class AiImageGenerationFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AiImageGeneration $generation) {
            if (! $generation->org_id && $generation->client_id) {
                $generation->org_id = (int) Client::find($generation->client_id)?->org_id;
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
            'uuid' => (string) Str::uuid(),
            'client_id' => Client::factory(),
            'request_id' => (string) Str::uuid(),
            'brief' => [
                'subject' => 'A sunlit cafe counter with a fresh pastry',
                'composition' => 'Close-up, shallow depth of field',
                'mood' => 'Warm and inviting',
                'palette' => [['name' => 'Teal', 'hex' => '#205557']],
                'style' => 'Natural light photography',
                'textOverlay' => null,
                'avoid' => [],
                'referenceAssetIds' => [],
            ],
            'art_direction' => null,
            'brief_hash' => hash('sha256', Str::random()),
            'provider' => 'null',
            'model' => 'placeholder',
            'quality_tier' => ImageQualityTier::Draft,
            'aspect_ratio' => ImageAspectRatio::Square_1_1,
            'variant_count' => 4,
            'status' => ImageGenerationStatus::Ready,
            'cost_estimate' => 0,
        ];
    }
}
