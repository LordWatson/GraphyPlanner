<?php

namespace App\Actions\Posts;

use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step I-1.5 — delete a post that never went out (a soft delete, so it's recoverable). Callers
 * authorize through `PostPolicy::delete`, which refuses anything that has been (or is being)
 * published: those can only be archived, so the record of what went live is never lost.
 *
 * A `scheduled` post's dispatch id is cleared first, so its queued publish job no-ops.
 *
 * Step C-3.2: a campaign deliverable started as this post is released (its `post_id` cleared),
 * so the slot reads "Not started" again and can be restarted.
 */
class DeletePostAction
{
    public function __invoke(Post $post, User $actor): void
    {
        DB::transaction(function () use ($post) {
            $post->update(['publish_dispatch_id' => null]);
            CampaignDeliverable::query()->where('post_id', $post->id)->update(['post_id' => null]);
            $post->delete();
        });

        Log::info('Post deleted (soft)', [
            'org_id' => $post->org_id,
            'client_id' => $post->client_id,
            'post_id' => $post->id,
            'user_id' => $actor->id,
            'status' => $post->status->value,
        ]);
    }
}
