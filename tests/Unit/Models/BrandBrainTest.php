<?php

namespace Tests\Unit\Models;

use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandBrainTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_grok_context_returns_markdown_and_json_from_fixture_data(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Inc']);

        $brandBrain = BrandBrain::factory()->for($client)->create([
            'voice' => [
                'tone' => 'Playful',
                'personality' => 'Witty best friend',
                'do_nots' => ['No jargon', 'No emojis'],
            ],
            'audience' => [
                'description' => 'Busy parents',
                'demographics' => '30-45',
                'pain_points' => ['No time'],
            ],
            'offer' => [
                'value_proposition' => 'Save time',
                'key_products' => ['Meal kits'],
                'pricing_notes' => 'Subscription based',
            ],
            'visual' => [
                'color_palette' => ['#FFFFFF', '#000000'],
                'typography' => 'Rounded sans',
                'imagery_style' => 'Warm and bright',
                'logo_usage_notes' => 'Centered on white',
            ],
            'music_policy' => [
                'allowed_genres' => ['Pop'],
                'disallowed_genres' => ['Metal'],
                'notes' => 'Keep it upbeat',
            ],
            'hashtag_policy' => [
                'always_use' => ['#acme'],
                'never_use' => ['#spam'],
                'rotation_notes' => 'Rotate weekly',
            ],
            'content_pillars' => ['Recipes', 'Family life'],
        ]);

        $context = $brandBrain->toGrokContext();

        $this->assertArrayHasKey('markdown', $context);
        $this->assertArrayHasKey('json', $context);

        $this->assertStringContainsString('Brand Brain: Acme Inc', $context['markdown']);
        $this->assertStringContainsString('Tone: Playful', $context['markdown']);
        $this->assertStringContainsString('No jargon, No emojis', $context['markdown']);
        $this->assertStringContainsString('Pain points: No time', $context['markdown']);
        $this->assertStringContainsString('Value proposition: Save time', $context['markdown']);
        $this->assertStringContainsString('Color palette: #FFFFFF, #000000', $context['markdown']);
        $this->assertStringContainsString('Allowed genres: Pop', $context['markdown']);
        $this->assertStringContainsString('Always use: #acme', $context['markdown']);
        $this->assertStringContainsString('Recipes, Family life', $context['markdown']);

        $this->assertSame('Playful', $context['json']['voice']['tone']);
        $this->assertSame(['Recipes', 'Family life'], $context['json']['content_pillars']);
    }

    public function test_to_grok_context_handles_missing_data_gracefully(): void
    {
        $client = Client::factory()->create(['name' => 'Empty Co']);
        $brandBrain = new BrandBrain(['client_id' => $client->id]);
        $brandBrain->client()->associate($client);

        $context = $brandBrain->toGrokContext();

        $this->assertStringContainsString('Brand Brain: Empty Co', $context['markdown']);
        $this->assertStringContainsString('Tone: —', $context['markdown']);
        $this->assertSame([], $context['json']['content_pillars']);
    }
}
