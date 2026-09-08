<?php

namespace Database\Factories;

use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandBrain>
 */
class BrandBrainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'voice' => [
                'tone' => 'Friendly and confident',
                'personality' => 'Approachable expert',
                'do_nots' => ['Avoid slang', 'No profanity'],
            ],
            'audience' => [
                'description' => fake()->sentence(),
                'demographics' => '25-45, urban professionals',
                'pain_points' => ['Lack of time', 'Too many options'],
            ],
            'offer' => [
                'value_proposition' => fake()->catchPhrase(),
                'key_products' => ['Flagship product', 'Seasonal bundle'],
                'pricing_notes' => 'Premium positioning',
            ],
            'visual' => [
                'color_palette' => ['#111111', '#F5F5F5'],
                'typography' => 'Sans-serif, bold headlines',
                'imagery_style' => 'Bright, candid lifestyle photography',
                'logo_usage_notes' => 'Always on light backgrounds',
            ],
            'music_policy' => [
                'allowed_genres' => ['Lo-fi', 'Acoustic'],
                'disallowed_genres' => ['Explicit hip-hop'],
                'notes' => 'Keep energy calm and upbeat',
            ],
            'hashtag_policy' => [
                'always_use' => ['#brand'],
                'never_use' => ['#competitor'],
                'rotation_notes' => 'Rotate 3-5 niche tags per post',
            ],
            'content_pillars' => ['Education', 'Behind the scenes', 'Social proof'],
        ];
    }
}
