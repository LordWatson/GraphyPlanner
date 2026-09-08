<?php

namespace Database\Factories;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Asset $asset) {
            if (! $asset->org_id && $asset->client_id) {
                $asset->org_id = Client::find($asset->client_id)?->org_id;
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
            'client_id' => Client::factory(),
            'source' => AssetSource::Url,
            'type' => AssetType::Image,
            'url' => fake()->imageUrl(),
            'rights' => null,
            'variant_group_id' => null,
        ];
    }

    /**
     * Indicate the asset is a Figma link.
     */
    public function figma(): static
    {
        return $this->state(fn () => [
            'source' => AssetSource::Figma,
            'type' => null,
            'url' => 'https://www.figma.com/file/'.fake()->uuid(),
        ]);
    }

    /**
     * Indicate the asset was uploaded to storage.
     */
    public function uploaded(): static
    {
        return $this->state(fn () => [
            'source' => AssetSource::Upload,
            'disk' => 'public',
            'path' => 'assets/'.fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(1000, 500000),
        ]);
    }
}
