<?php

namespace Tests\Unit\Support\AI;

use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Tests\TestCase;

class AiDtoTest extends TestCase
{
    public function test_ai_caption_suggestion_constructs_and_serializes(): void
    {
        $suggestion = new AiCaptionSuggestion(
            caption: 'A great caption',
            hashtags: ['#graphy', '#social'],
            altText: 'A descriptive alt text',
            firstComment: 'Link in bio!',
        );

        $this->assertSame('A great caption', $suggestion->caption);
        $this->assertSame([
            'caption' => 'A great caption',
            'hashtags' => ['#graphy', '#social'],
            'altText' => 'A descriptive alt text',
            'firstComment' => 'Link in bio!',
        ], $suggestion->jsonSerialize());
    }

    public function test_ai_caption_suggestions_serializes_each_item(): void
    {
        $suggestions = new AiCaptionSuggestions([
            new AiCaptionSuggestion('Caption one'),
            new AiCaptionSuggestion('Caption two', ['#two']),
        ]);

        $json = $suggestions->jsonSerialize();

        $this->assertCount(2, $json);
        $this->assertSame('Caption one', $json[0]['caption']);
        $this->assertSame(['#two'], $json[1]['hashtags']);
    }

    public function test_ai_post_idea_constructs_and_serializes(): void
    {
        $idea = new AiPostIdea(
            title: 'Behind the scenes reel',
            description: 'Show the team prepping for launch day.',
            platforms: ['instagram', 'tiktok'],
            suggestedCaption: 'Here is a sneak peek!',
            suggestedPostAt: '2026-10-20T09:00:00Z',
        );

        $this->assertSame([
            'title' => 'Behind the scenes reel',
            'description' => 'Show the team prepping for launch day.',
            'platforms' => ['instagram', 'tiktok'],
            'suggestedCaption' => 'Here is a sneak peek!',
            'suggestedPostAt' => '2026-10-20T09:00:00Z',
        ], $idea->jsonSerialize());
    }

    public function test_ai_post_ideas_serializes_each_item(): void
    {
        $ideas = new AiPostIdeas([
            new AiPostIdea('Idea one', 'Description one'),
        ]);

        $json = $ideas->jsonSerialize();

        $this->assertCount(1, $json);
        $this->assertSame('Idea one', $json[0]['title']);
    }

    public function test_ai_trend_constructs_and_serializes(): void
    {
        $trend = new AiTrend(
            title: 'Short-form tutorials are trending',
            summary: 'Creators are posting quick how-to clips under 30 seconds.',
            platforms: ['tiktok'],
            confidence: 0.75,
        );

        $this->assertSame([
            'title' => 'Short-form tutorials are trending',
            'summary' => 'Creators are posting quick how-to clips under 30 seconds.',
            'platforms' => ['tiktok'],
            'confidence' => 0.75,
        ], $trend->jsonSerialize());
    }

    public function test_ai_trends_serializes_each_item(): void
    {
        $trends = new AiTrends([
            new AiTrend('Trend one', 'Summary one'),
        ]);

        $json = $trends->jsonSerialize();

        $this->assertCount(1, $json);
        $this->assertSame('Trend one', $json[0]['title']);
    }
}
