<?php

namespace App\Services\Posts;

use App\Contracts\PostWorkflow;
use App\Models\Organization;
use App\Models\Post;

/**
 * Step B-3.1 — picks the `PostWorkflow` for a workspace from its org type. Bound as a singleton in
 * `AppServiceProvider`; `TransitionPostStatusAction`, `PostController` and `TransitionPostRequest`
 * ask the resolver for the legal moves instead of reading `PostStatusTransitionMap` directly, so a
 * business post follows its own lifecycle while agency behaviour is unchanged.
 */
class PostWorkflowResolver
{
    public function __construct(
        private readonly AgencyPostWorkflow $agency,
        private readonly BusinessPostWorkflow $business,
    ) {}

    public function for(Organization $organization): PostWorkflow
    {
        return $organization->isBusiness() ? $this->business : $this->agency;
    }

    public function forPost(Post $post): PostWorkflow
    {
        // Caches the relation on the model (works under `preventLazyLoading`), so the chosen
        // workflow can read `$post->organization` again without a second query or a lazy-load throw.
        $post->loadMissing('organization');

        return $this->for($post->organization);
    }
}
