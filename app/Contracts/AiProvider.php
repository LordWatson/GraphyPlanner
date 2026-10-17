<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;

/**
 * Contract for AI-assisted trend discovery and suggestions (Step 2.1), kept
 * swappable the same way App\Contracts\PublishAdapter and
 * App\Contracts\MusicProvider are — an interface first, bound in a Service
 * Provider — so the vendor (DeepSeek, Step 2.2) can be mocked in tests and,
 * later, swapped without touching any Action/controller that depends on it.
 */
interface AiProvider
{
    /**
     * Suggest captions (plus hashtags/alt text/first comment) for the given
     * post, using its client's brand voice and platform constraints.
     */
    public function suggestCaptions(Post $post): AiCaptionSuggestions;

    /**
     * Recommend fresh post ideas for the given client, tied to its active
     * campaigns and brand pillars.
     */
    public function recommendPosts(Client $client): AiPostIdeas;

    /**
     * Discover current trends relevant to the given client across the
     * requested platforms.
     *
     * @param  array<int, string>  $platforms
     */
    public function discoverTrends(Client $client, array $platforms): AiTrends;

    /**
     * Act as art director for the AI Image Studio (Step 16.5): turn an idea/post into a
     * structured image brief grounded in the client's Brand Brain. Never generates pixels and
     * never throws: implementations fall back to a deterministic, brand-derived brief.
     */
    public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief;
}
