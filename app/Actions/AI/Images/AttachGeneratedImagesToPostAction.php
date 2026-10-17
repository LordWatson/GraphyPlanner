<?php

namespace App\Actions\AI\Images;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageVariant;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Step 16.10: attaches the selected variants' assets to a post (`post_assets`), marks them
 * selected, re-runs the §6 checklist and writes an activity entry ("Added 2 AI images"), all in
 * one transaction. Never changes the post's status: a human still sends it for review.
 */
class AttachGeneratedImagesToPostAction
{
    public function __construct(private readonly EvaluatePostChecklistAction $evaluateChecklist) {}

    /**
     * @param  Collection<int, AiImageVariant>  $variants
     */
    public function __invoke(Post $post, Collection $variants, User $user): int
    {
        $usable = $variants->filter(fn (AiImageVariant $variant) => $variant->status === ImageVariantStatus::Ready
            && $variant->asset_id !== null
            && $variant->asset?->client_id === $post->client_id);

        if ($usable->isEmpty()) {
            throw new InvalidArgumentException('Pick at least one ready image for this client.');
        }

        $attached = DB::transaction(function () use ($post, $usable, $user) {
            $result = $post->assets()->syncWithoutDetaching($usable->pluck('asset_id')->all());
            $added = count($result['attached']);

            AiImageVariant::query()
                ->whereKey($usable->modelKeys())
                ->whereNull('selected_at')
                ->update(['selected_at' => now()]);

            $post->unsetRelation('assets');
            $post->update(['checklist_snapshot' => ($this->evaluateChecklist)($post)]);

            if ($added > 0) {
                PostActivityLog::create([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'from_status' => $post->status,
                    'to_status' => $post->status,
                    'note' => $added === 1 ? 'Added 1 AI image' : "Added {$added} AI images",
                ]);
            }

            return $added;
        });

        Log::info('AI images attached to post', [
            'org_id' => $post->org_id,
            'client_id' => $post->client_id,
            'post_id' => $post->id,
            'user_id' => $user->id,
            'variant_ids' => $usable->modelKeys(),
            'attached' => $attached,
        ]);

        return $attached;
    }
}
