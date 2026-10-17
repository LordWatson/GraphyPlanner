<?php

namespace App\Actions\AI\Images;

use App\Actions\AI\RecommendPostsAction;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\AiPostIdea as AiPostIdeaDto;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The studio's "Use in post" (Step 16.9/16.10): resolves the post the set serves (its own post,
 * or its idea's draft) and, for an idea without a post yet, creates that draft through the
 * existing RecommendPostsAction::persist() path first, then attaches the selected variants.
 */
class UseGeneratedImagesInPostAction
{
    public function __construct(
        private readonly RecommendPostsAction $recommendPosts,
        private readonly AttachGeneratedImagesToPostAction $attach,
    ) {}

    /**
     * @param  array<int, int>  $variantIds
     */
    public function __invoke(AiImageGeneration $generation, array $variantIds, User $user): Post
    {
        // Selections may span several sets of the same idea/post; never another client's.
        $variants = AiImageVariant::query()
            ->with('asset')
            ->whereKey($variantIds)
            ->whereHas('generation', fn ($query) => $query->where('client_id', $generation->client_id))
            ->get();

        return DB::transaction(function () use ($generation, $variants, $user) {
            $post = $this->resolvePost($generation);

            ($this->attach)($post, $variants, $user);

            return $post;
        });
    }

    private function resolvePost(AiImageGeneration $generation): Post
    {
        $post = $generation->post ?? $generation->idea?->post;

        if ($post === null && $generation->idea !== null) {
            $idea = $generation->idea;

            $post = $this->recommendPosts->persist($generation->client, new AiPostIdeaDto(
                title: $idea->title,
                description: (string) $idea->description,
                platforms: $idea->platforms ?? [],
                suggestedCaption: $idea->suggested_caption,
                suggestedPostAt: $idea->suggested_post_at,
            ));
        }

        if ($post === null) {
            throw new InvalidArgumentException('This image set is not linked to a post or idea.');
        }

        if ($generation->post_id !== $post->id) {
            $generation->update(['post_id' => $post->id]);
        }

        return $post;
    }
}
