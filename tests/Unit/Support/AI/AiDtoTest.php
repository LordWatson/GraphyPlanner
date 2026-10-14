<?php

namespace Tests\Unit\Support\AI;

use App\Enums\Platform;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Tests\TestCase;

class AiDtoTest extends TestCase
{
    public function test_caption_suggestion_serializes_to_expected_shape(): void
    {
        $s = new AiCaptionSuggestion(
            caption: 'On-brand caption',
            hashtags: ['#one', '#two'],
            altText: 'Alt text here',
            firstComment: 'First comment',
        );

        $this->assertSame([
            'caption' => 'On-brand caption',
            'hashtags' => ['#one', '#two'],
            'altText' => 'Alt text here',
            'firstComment' => 'First comment',
        ], $s->toArray());

        $list = new AiCaptionSuggestions([$s]);
        $this->assertCount(1, $list);
        $this->assertSame([$s->toArray()], $list->toArray());
    }

    public function test_post_idea_serializes_platforms_as_strings(): void
    {
        $idea = new AiPostIdea(
            title: 'Behind-the-scenes',
            description: 'Show process and team',
            platforms: [Platform::Instagram, Platform::LinkedIn],
            tags: ['bts', 'team'],
        );

        $this->assertSame([
            'title' => 'Behind-the-scenes',
            'description' => 'Show process and team',
            'platforms' => ['instagram', 'linkedin'],
            'tags' => ['bts', 'team'],
        ], $idea->toArray());

        $ideas = new AiPostIdeas([$idea]);
        $this->assertCount(1, $ideas);
    }

    public function test_trend_serializes_with_optional_confidence(): void
    {
        $trend = new AiTrend(
            topic: 'UGC collaborations',
            description: 'Leverage creators for authentic reach',
            platforms: [Platform::TikTok],
            confidence: 0.8,
        );

        $this->assertSame([
            'topic' => 'UGC collaborations',
            'description' => 'Leverage creators for authentic reach',
            'platforms' => ['tiktok'],
            'confidence' => 0.8,
        ], $trend->toArray());

        $trends = new AiTrends([$trend]);
        $this->assertCount(1, $trends);
    }
}
