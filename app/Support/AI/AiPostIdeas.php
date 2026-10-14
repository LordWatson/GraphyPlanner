<?php

namespace App\Support\AI;

use JsonSerializable;

/**
 * A collection of AiPostIdea items returned by AiProvider::recommendPosts()
 * (Step 2.1).
 */
final readonly class AiPostIdeas implements JsonSerializable
{
    /**
     * @param  array<int, AiPostIdea>  $ideas
     */
    public function __construct(
        public array $ideas = [],
    ) {}

    /**
     * @return array<int, array{title: string, description: string, platforms: array<int, string>, suggestedCaption: ?string, suggestedPostAt: ?string}>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn (AiPostIdea $idea): array => $idea->jsonSerialize(),
            $this->ideas,
        );
    }
}
