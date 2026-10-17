<?php

namespace App\Support\AI\Images;

use App\Enums\ImageAspectRatio;
use App\Models\AiPostIdea;
use App\Models\Post;

/**
 * Validated input for GenerateImageSetAction (Step 16.7): what the set serves (an idea and/or a
 * post), the format and size of the set, the person's art-direction overrides, and the
 * client-generated `requestId` that makes a double submit resolve to the same set.
 */
final readonly class GenerateImageSetData
{
    /**
     * @param  array{subject?: string, mood?: string, style?: string, composition?: string, excludedColours?: array<int, string>}  $overrides
     */
    public function __construct(
        public string $requestId,
        public ImageAspectRatio $aspectRatio,
        public int $variantCount,
        public ?AiPostIdea $idea = null,
        public ?Post $post = null,
        public array $overrides = [],
        public bool $withHeadline = false,
        public ?string $headline = null,
    ) {}

    /**
     * The art-direction fields persisted on the generation so "Generate again" can re-apply them.
     *
     * @return array<string, mixed>
     */
    public function artDirection(): array
    {
        return [
            ...$this->overrides,
            'withHeadline' => $this->withHeadline,
            'headline' => $this->headline,
        ];
    }
}
