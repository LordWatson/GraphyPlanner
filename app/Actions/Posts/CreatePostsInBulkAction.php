<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\User;
use App\Support\Posts\BulkPostRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-5.8 (Phase 10) — create a whole batch of posts from parsed CSV rows in one transaction
 * (all-or-nothing, principle #5: a half-imported batch is worse than none). Each row goes through
 * the existing `CreatePostAction`, so every post still gets independently-computed per-target
 * `scheduled_at_utc` — bulk import never jumps straight to `scheduled`; posts land as drafts for a
 * human to send on.
 */
class CreatePostsInBulkAction
{
    /**
     * @param  Collection<int, BulkPostRow>  $rows
     * @return int the number of posts created
     */
    public function __invoke(Client $client, Collection $rows, ?User $user = null, CreatePostAction $createPost = new CreatePostAction): int
    {
        return DB::transaction(function () use ($client, $rows, $user, $createPost) {
            $created = 0;

            foreach ($rows as $row) {
                $data = $row->toCreateData(PostStatus::Draft->value);

                if ($user !== null) {
                    $data['created_by'] = $user->id;
                }

                $post = $createPost($client, $data);

                // CreatePostAction only sets each target's schedule, so a row's chosen format is
                // applied to its channels here.
                if ($row->format !== null) {
                    $post->targets()->update(['format' => $row->format]);
                }

                $created++;
            }

            Log::info('Posts bulk imported', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'user_id' => $user?->id,
                'row_count' => $rows->count(),
                'created' => $created,
            ]);

            return $created;
        });
    }
}
