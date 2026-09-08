<?php

namespace Database\Seeders;

use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Database\Seeder;

class BrandBrainSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $acme = Client::firstWhere('name', 'Acme Corp');

        if (! $acme) {
            return;
        }

        BrandBrain::updateOrCreate(
            ['client_id' => $acme->id],
            [
                'voice' => [
                    'tone' => 'Confident and warm',
                    'personality' => 'The helpful expert neighbor',
                    'do_nots' => ['No corporate jargon', 'No hard-selling language'],
                ],
                'audience' => [
                    'description' => 'Value-conscious households shopping for everyday essentials',
                    'demographics' => '28-55, suburban, household decision-makers',
                    'pain_points' => ['Limited time to shop', 'Price sensitivity'],
                ],
                'offer' => [
                    'value_proposition' => 'Quality essentials at a fair price, delivered fast',
                    'key_products' => ['Household essentials', 'Seasonal bundles'],
                    'pricing_notes' => 'Everyday low pricing, occasional bundle promos',
                ],
                'visual' => [
                    'color_palette' => ['#0F172A', '#F97316', '#FFFFFF'],
                    'typography' => 'Clean sans-serif, bold headlines',
                    'imagery_style' => 'Bright, candid, real-life settings',
                    'logo_usage_notes' => 'Always on white or dark navy backgrounds',
                ],
                'music_policy' => [
                    'allowed_genres' => ['Acoustic', 'Indie pop'],
                    'disallowed_genres' => ['Explicit hip-hop', 'Heavy metal'],
                    'notes' => 'Keep tracks upbeat but not overpowering',
                ],
                'hashtag_policy' => [
                    'always_use' => ['#AcmeCorp', '#EverydayEssentials'],
                    'never_use' => ['#cheap'],
                    'rotation_notes' => 'Rotate 3-5 seasonal tags per campaign',
                ],
                'content_pillars' => ['Product spotlights', 'Customer stories', 'Behind the scenes', 'Promotions'],
            ]
        );
    }
}
