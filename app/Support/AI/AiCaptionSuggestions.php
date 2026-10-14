<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A collection of AiCaptionSuggestion items returned by
 * AiProvider::suggestCaptions() (Step 2.1).
 */
final readonly class AiCaptionSuggestions implements JsonSerializable
{
    /**
     * @param  array<int, AiCaptionSuggestion>  $suggestions
     */
    public function __construct(
        public array $suggestions = [],
    ) {}

    /**
     * @return array<int, array{caption: string, hashtags: array<int, string>, altText: ?string, firstComment: ?string}>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn (AiCaptionSuggestion $suggestion): array => $suggestion->jsonSerialize(),
            $this->suggestions,
        );
    }
}
