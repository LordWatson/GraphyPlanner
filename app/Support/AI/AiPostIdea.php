<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A single AI-generated post idea (Step 2.1): a concept, a suggested caption
 * starting point, platforms it fits, and an optional suggested posting time,
 * so RecommendPostsAction (Step 2.3) can optionally persist it as a draft Post.
 */
final readonly class AiPostIdea implements JsonSerializable
{
    /**
     * @param  array<int, string>  $platforms
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $platforms = [],
        public ?string $suggestedCaption = null,
        public ?string $suggestedPostAt = null,
    ) {}

    /**
     * @return array{title: string, description: string, platforms: array<int, string>, suggestedCaption: ?string, suggestedPostAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'platforms' => $this->platforms,
            'suggestedCaption' => $this->suggestedCaption,
            'suggestedPostAt' => $this->suggestedPostAt,
        ];
    }
}
