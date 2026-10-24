<?php

namespace Tests\Unit\Inbox;

use App\Enums\CommentSentiment;
use App\Services\Inbox\SentimentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step M-9.1 — the deterministic triage classifier. No AI, so it's a pure function of the text.
 */
class SentimentClassifierTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_classifies_a_comment(string $body, CommentSentiment $expected): void
    {
        $this->assertSame($expected, (new SentimentClassifier)->classify($body));
    }

    /**
     * @return array<string, array{0: string, 1: CommentSentiment}>
     */
    public static function cases(): array
    {
        return [
            'praise' => ['This is amazing, I love it! ❤️', CommentSentiment::Positive],
            'thanks' => ['Thank you so much for this', CommentSentiment::Positive],
            'question mark' => ['How much is shipping to the UK?', CommentSentiment::Question],
            'interrogative without mark' => ['where can i buy this', CommentSentiment::Question],
            'complaint' => ['This is terrible, I want a refund', CommentSentiment::Negative],
            'angry question stays negative' => ['why is my order still broken??', CommentSentiment::Negative],
            'plain statement' => ['Saw this yesterday downtown', CommentSentiment::Neutral],
            'empty' => ['', CommentSentiment::Neutral],
        ];
    }

    public function test_sentiment_precedence_and_reply_flags(): void
    {
        $this->assertTrue(CommentSentiment::Question->needsReply());
        $this->assertTrue(CommentSentiment::Negative->needsReply());
        $this->assertFalse(CommentSentiment::Positive->needsReply());
        $this->assertFalse(CommentSentiment::Neutral->needsReply());

        $this->assertGreaterThan(CommentSentiment::Negative->rank(), CommentSentiment::Question->rank());
        $this->assertGreaterThan(CommentSentiment::Positive->rank(), CommentSentiment::Negative->rank());
    }
}
