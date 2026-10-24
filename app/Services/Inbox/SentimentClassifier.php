<?php

namespace App\Services\Inbox;

use App\Enums\CommentSentiment;
use Illuminate\Support\Str;

/**
 * Step M-9.1 — a fast, deterministic sentiment tag for an inbound comment, run at ingestion. No AI
 * budget is spent on triage (the one AI surface in the inbox is drafting replies); this is a plain
 * keyword + punctuation heuristic, so it costs nothing, never calls a vendor, and is trivially
 * testable.
 *
 * Precedence is deliberate: a complaint outranks a question (an angry question is still a complaint
 * and the most urgent thing to see), a question outranks praise, and anything unrecognised is
 * neutral. Both negative and question tag the conversation "reply needed" for the SLA, so the exact
 * split never changes whether something surfaces — only how it reads at a glance.
 */
class SentimentClassifier
{
    /** @var list<string> */
    private const NEGATIVE = [
        'disappointed', 'terrible', 'worst', 'awful', 'hate', 'broken', 'scam', 'refund',
        'never again', 'rubbish', 'poor', 'angry', 'unacceptable', 'complaint', 'complain',
        'useless', 'waste', 'horrible', 'disgusting', 'rude', 'cancel', 'overpriced', 'misleading',
        'not working', "doesn't work", "won't work", 'ripoff', 'rip off', 'fraud', '👎', '😠', '😡', '🤬',
    ];

    /** @var list<string> */
    private const POSITIVE = [
        'love', 'great', 'amazing', 'awesome', 'thank', 'thanks', 'beautiful', 'perfect',
        'excellent', 'brilliant', 'well done', 'wonderful', 'fantastic', 'gorgeous', 'best',
        'incredible', 'obsessed', 'stunning', 'congrats', 'congratulations', 'nice', 'good job',
        '😍', '❤️', '🔥', '👏', '💯', '👍', '🙌', '😊', '🥰',
    ];

    /** @var list<string> */
    private const INTERROGATIVES = [
        'how ', 'what ', 'when ', 'where ', 'why ', 'who ', 'which ', 'can i', 'can you', 'could you',
        'do you', 'does it', 'is it', 'are you', 'will it', 'would you', 'any chance', 'how much',
        'how do', 'is there', 'are there',
    ];

    public function classify(string $body): CommentSentiment
    {
        $text = Str::lower(trim($body));

        if ($text === '') {
            return CommentSentiment::Neutral;
        }

        if ($this->containsAny($text, self::NEGATIVE)) {
            return CommentSentiment::Negative;
        }

        if ($this->looksLikeQuestion($text)) {
            return CommentSentiment::Question;
        }

        if ($this->containsAny($text, self::POSITIVE)) {
            return CommentSentiment::Positive;
        }

        return CommentSentiment::Neutral;
    }

    private function looksLikeQuestion(string $text): bool
    {
        if (str_contains($text, '?')) {
            return true;
        }

        // Leading interrogative — "how do I order", "where can I buy" — with no question mark.
        $padded = $text.' ';

        foreach (self::INTERROGATIVES as $opener) {
            if (str_starts_with($padded, $opener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
