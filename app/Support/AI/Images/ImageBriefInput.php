<?php

namespace App\Support\AI\Images;

/**
 * What the art director (AiProvider::draftImageBrief(), Step 16.5) works from: the idea/post copy,
 * its campaign and platforms, plus any art-direction fields a person typed in the studio — those
 * always win over what the model proposes.
 */
final readonly class ImageBriefInput
{
    /**
     * @param  array<int, string>  $platforms
     * @param  array{subject?: string, mood?: string, style?: string, composition?: string, excludedColours?: array<int, string>}  $overrides
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $caption = null,
        public ?string $campaign = null,
        public array $platforms = [],
        public array $overrides = [],
        public bool $withHeadline = false,
        public ?string $headline = null,
    ) {}

    /**
     * Stable representation for the brief cache key (`brief_hash`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $overrides = $this->overrides;
        ksort($overrides);

        $platforms = $this->platforms;
        sort($platforms);

        return [
            'title' => $this->title,
            'description' => $this->description,
            'caption' => $this->caption,
            'campaign' => $this->campaign,
            'platforms' => $platforms,
            'overrides' => $overrides,
            'withHeadline' => $this->withHeadline,
            'headline' => $this->headline,
        ];
    }
}
