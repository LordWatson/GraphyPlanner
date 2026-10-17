<?php

namespace App\Support\AI\Images;

use App\Enums\Platform;
use App\Models\AiImageGeneration;
use App\Models\AiPostIdea;
use App\Models\Post;
use App\Support\AI\ClientContextBuilder;
use Illuminate\Support\Str;

/**
 * Turns what an image set serves (an AI post idea, a draft post, or both) plus the studio's
 * art-direction fields into an ImageBriefInput (Step 16.5). Used when a set is prepared on the
 * queue and when the studio pre-fills its brand defaults.
 */
class ImageBriefInputResolver
{
    public function __construct(private readonly ClientContextBuilder $context = new ClientContextBuilder) {}

    /**
     * @param  array<string, mixed>  $artDirection
     */
    public function resolve(?AiPostIdea $idea, ?Post $post, array $artDirection = []): ImageBriefInput
    {
        $post?->loadMissing('targets.socialAccount', 'campaign');

        $title = $idea->title
            ?? Str::limit(trim(strtok((string) $post?->master_caption, "\n") ?: ''), 80, '')
            ?: 'Social post';

        $platforms = $idea->platforms ?? [];

        if ($platforms === [] && $post !== null) {
            $platforms = array_map(fn (Platform $platform) => $platform->value, $post->targetPlatforms());
        }

        $overrides = array_filter([
            'subject' => $this->string($artDirection['subject'] ?? null),
            'mood' => $this->string($artDirection['mood'] ?? null),
            'style' => $this->string($artDirection['style'] ?? null),
            'composition' => $this->string($artDirection['composition'] ?? null),
            'excludedColours' => array_values(array_filter(array_map(
                fn ($colour) => is_string($colour) ? trim($colour) : '',
                is_array($artDirection['excludedColours'] ?? null) ? $artDirection['excludedColours'] : [],
            ))),
        ], fn ($value) => $value !== null && $value !== []);

        return new ImageBriefInput(
            title: $title,
            description: $idea?->description,
            caption: $post->master_caption ?? $idea?->suggested_caption,
            campaign: $post?->campaign !== null ? $this->context->describeCampaign($post->campaign) : null,
            platforms: array_values(array_map('strval', $platforms)),
            overrides: $overrides,
            withHeadline: (bool) ($artDirection['withHeadline'] ?? false),
            headline: $this->string($artDirection['headline'] ?? null),
        );
    }

    public function forGeneration(AiImageGeneration $generation): ImageBriefInput
    {
        return $this->resolve($generation->idea, $generation->post, $generation->art_direction ?? []);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
