<?php

namespace Tests\Unit\Services\AI;

use App\Models\BrandBrain;
use App\Models\Client;
use App\Services\AI\BrandFitScorer;
use App\Support\AI\AiCaptionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-7.4 (dev plan Step 2.12) — the deterministic brand-fit heuristic.
 */
class BrandFitScorerTest extends TestCase
{
    use RefreshDatabase;

    private function brain(): BrandBrain
    {
        $client = Client::factory()->create();

        return BrandBrain::factory()->for($client)->create([
            'voice' => ['tone' => 'warm', 'personality' => 'friendly', 'do_nots' => ['hard sell']],
            'hashtag_policy' => ['always_use' => ['graphy'], 'never_use' => ['cheap'], 'rotation_notes' => null],
            'content_pillars' => ['Education', 'Behind the scenes'],
        ]);
    }

    public function test_an_on_brand_caption_with_the_always_use_hashtag_and_a_pillar_scores_high(): void
    {
        $result = (new BrandFitScorer)->score(
            $this->brain(),
            new AiCaptionSuggestion('Education is at our heart — here is a tip.', ['graphy'], null, null),
        );

        $this->assertGreaterThanOrEqual(75, $result['score']);
    }

    public function test_a_caption_using_a_never_say_phrase_scores_low(): void
    {
        $result = (new BrandFitScorer)->score(
            $this->brain(),
            new AiCaptionSuggestion('Get it cheap while stocks last!', ['graphy'], null, null),
        );

        $this->assertLessThan(50, $result['score']);
        $this->assertStringContainsString('cheap', $result['reason']);
    }

    public function test_a_do_not_phrase_also_scores_low(): void
    {
        $result = (new BrandFitScorer)->score(
            $this->brain(),
            new AiCaptionSuggestion('This is a hard sell, buy now.', [], null, null),
        );

        $this->assertLessThan(50, $result['score']);
    }

    public function test_a_client_with_no_brand_brain_gets_a_neutral_score(): void
    {
        $result = (new BrandFitScorer)->score(
            null,
            new AiCaptionSuggestion('Any caption here.', [], null, null),
        );

        $this->assertSame(55, $result['score']);
    }

    public function test_the_score_is_clamped_to_0_100(): void
    {
        $result = (new BrandFitScorer)->score(
            $this->brain(),
            new AiCaptionSuggestion('Education behind the scenes', ['graphy'], null, null),
        );

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
