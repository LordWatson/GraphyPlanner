<?php

namespace Tests\Unit\Actions\AI;

use App\Actions\AI\Images\DraftImageBriefAction;
use App\Contracts\AiProvider;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\NullAiProvider;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 16.5: DeepSeek as art director: schema enforcement + retry, guardrails, hashtags excluded,
 * the brand-derived fallback and the brief_hash cache.
 */
class DraftImageBriefActionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.deepseek.api_key', 'sk-test');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');
        Config::set('ai.images.brief_model', 'deepseek-flash');
        Config::set('ai.guardrails.prohibited_topics', ['gambling']);

        $this->client = Client::factory()->create();
        BrandBrain::factory()->for($this->client)->create([
            'voice' => ['tone' => 'Warm', 'personality' => 'Friendly', 'do_nots' => ['No cartoons']],
            'visual' => [
                'color_palette' => ['Teal #205557', 'Cream #F5F2E8'],
                'typography' => 'Poppins',
                'imagery_style' => 'Candid natural-light photography',
                'logo_usage_notes' => 'Bottom right only',
            ],
            'hashtag_policy' => ['always_use' => [], 'never_use' => ['cheapdeals'], 'rotation_notes' => null],
        ]);
    }

    private function input(array $overrides = []): ImageBriefInput
    {
        return new ImageBriefInput(
            title: 'Autumn launch',
            description: 'A new pumpkin pastry',
            caption: 'Autumn is here #autumn #pastry',
            platforms: ['instagram'],
            overrides: $overrides,
        );
    }

    private function chat(array $json): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($json)]]]];
    }

    private function action(): DraftImageBriefAction
    {
        $this->app->instance(AiProvider::class, new DeepSeekProvider);

        return $this->app->make(DraftImageBriefAction::class);
    }

    public function test_deepseek_drafts_the_creative_fields_and_the_brand_supplies_the_rest(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chat([
            'subject' => 'A pumpkin pastry on a marble counter',
            'composition' => 'Close-up, left third',
            'mood' => 'Cosy',
            'style' => 'Natural light',
            'avoid' => ['clutter'],
        ]))]);

        $result = ($this->action())($this->client, $this->input(['excludedColours' => ['Cream']]));
        $brief = $result['brief'];

        $this->assertSame('A pumpkin pastry on a marble counter', $brief->subject);
        $this->assertSame([['name' => 'Teal', 'hex' => '#205557']], $brief->palette);
        $this->assertNull($brief->textOverlay);
        $this->assertContains('No cartoons', $brief->avoid);
        $this->assertFalse($result['cached']);

        Http::assertSent(fn (Request $request) => $request['model'] === 'deepseek-flash'
            && ! str_contains($request['messages'][1]['content'], '#autumn'));
    }

    public function test_schema_invalid_output_is_retried_once(): void
    {
        Http::fakeSequence('*/chat/completions')
            ->push($this->chat(['subject' => 'x']))
            ->push($this->chat(['subject' => 'A pastry', 'composition' => 'c', 'mood' => 'm', 'style' => 's']));

        $brief = ($this->action())($this->client, $this->input())['brief'];

        $this->assertSame('A pastry', $brief->subject);
        Http::assertSentCount(2);
    }

    public function test_a_failing_deepseek_falls_back_to_the_brand_derived_brief(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'down']], 400)]);

        $brief = ($this->action())($this->client, $this->input())['brief'];

        $this->assertStringContainsString('Autumn launch', $brief->subject);
        $this->assertSame('Candid natural-light photography', $brief->style);
    }

    public function test_banned_phrases_and_hashtags_never_reach_the_brief(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chat([
            'subject' => 'A #pastry promo for cheapdeals lovers near a gambling table',
            'composition' => 'Close-up',
            'mood' => 'No cartoons, cosy',
            'style' => 'Photo',
            'avoid' => ['gambling chips', 'cheapdeals signage', 'blur'],
        ]))]);

        $brief = ($this->action())($this->client, $this->input())['brief'];
        $serialized = strtolower((string) json_encode($brief->toArray()));

        $this->assertStringNotContainsString('cheapdeals', $serialized);
        $this->assertStringNotContainsString('gambling', $serialized);
        $this->assertStringNotContainsString('#pastry', $serialized);
        $this->assertStringNotContainsString('no cartoons', strtolower($brief->mood));
        $this->assertContains('blur', $brief->avoid);
    }

    public function test_identical_inputs_hit_the_cache_with_zero_extra_calls(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chat(['subject' => 'A', 'composition' => 'c', 'mood' => 'm', 'style' => 's']))]);

        $first = ($this->action())($this->client, $this->input());
        $second = ($this->action())($this->client, $this->input());
        $third = ($this->action())($this->client, $this->input(['mood' => 'Bright']));

        $this->assertSame($first['hash'], $second['hash']);
        $this->assertTrue($second['cached']);
        $this->assertNotSame($first['hash'], $third['hash']);
        $this->assertSame('Bright', $third['brief']->mood);
        Http::assertSentCount(2);
    }

    public function test_person_overrides_always_win_and_headline_is_opt_in(): void
    {
        $this->app->instance(AiProvider::class, new NullAiProvider);
        $action = $this->app->make(DraftImageBriefAction::class);

        $brief = $action($this->client, new ImageBriefInput(
            title: 'Autumn launch',
            overrides: ['subject' => 'Two friends sharing a pastry', 'style' => 'Film photo'],
            withHeadline: true,
            headline: 'Autumn is here',
        ))['brief'];

        $this->assertSame('Two friends sharing a pastry', $brief->subject);
        $this->assertSame('Film photo', $brief->style);
        $this->assertSame('Autumn is here', $brief->textOverlay);
    }
}
