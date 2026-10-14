<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A single AI-generated caption suggestion (Step 2.1), including optional
 * hashtags, alt text, and first-comment copy so the editor's "Suggest with
 * AI" panel (Step 2.4) can offer a complete, ready-to-apply package.
 */
final readonly class AiCaptionSuggestion implements JsonSerializable
{
    /**
     * @param  array<int, string>  $hashtags
     */
    public function __construct(
        public string $caption,
        public array $hashtags = [],
        public ?string $altText = null,
        public ?string $firstComment = null,
    ) {}

    /**
     * @return array{caption: string, hashtags: array<int, string>, altText: ?string, firstComment: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'caption' => $this->caption,
            'hashtags' => $this->hashtags,
            'altText' => $this->altText,
            'firstComment' => $this->firstComment,
        ];
    }
}
