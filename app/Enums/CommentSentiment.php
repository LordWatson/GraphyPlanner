<?php

namespace App\Enums;

/**
 * Step M-9.1 — the triage tag a comment/conversation carries, set at ingestion by the deterministic
 * `App\Services\Inbox\SentimentClassifier` (keyword + punctuation heuristics, no AI budget spent).
 * `Question` outranks the others so a customer question is never buried under praise, and
 * `Negative`/`Question` are what mark a conversation "reply needed" for the SLA.
 */
enum CommentSentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positive',
            self::Neutral => 'Neutral',
            self::Negative => 'Negative',
            self::Question => 'Question',
        };
    }

    /**
     * Whether a comment with this sentiment should flag its conversation as needing a studio reply.
     */
    public function needsReply(): bool
    {
        return $this === self::Negative || $this === self::Question;
    }

    /**
     * Precedence when rolling several comments up to one conversation sentiment: a question or a
     * complaint always wins over praise or small talk, so the thread surfaces by its most urgent tone.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Question => 3,
            self::Negative => 2,
            self::Positive => 1,
            self::Neutral => 0,
        };
    }
}
