<?php

namespace App\Services\Grids;

use App\Contracts\ProfileFeedSource;
use App\Enums\AssetType;
use App\Enums\PostTargetStatus;
use App\Models\Asset;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Grids\ProfileFeed;
use App\Support\Grids\ProfileFeedItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step 18.3 — the fallback feed: the Instagram posts the app itself published on this account,
 * newest first, each shown by its first item's thumbnail. Used when the account hasn't done the
 * Facebook Login connect, so the UI says "Showing posts sent from Alima."
 */
class PublishedPostsProfileFeedSource implements ProfileFeedSource
{
    public function recent(SocialAccount $account, int $limit = 30): ProfileFeed
    {
        $targets = PostTarget::query()
            ->where('social_account_id', $account->id)
            ->where('status', PostTargetStatus::Published)
            ->where(fn (Builder $query) => $query->whereNull('format')->orWhere('format', '!=', 'story'))
            ->whereHas('post', fn (Builder $post) => $post->withoutGlobalScopes()->whereNull('deleted_at'))
            ->with(['post' => fn ($post) => $post->withoutGlobalScopes()->with('assets')])
            ->orderByDesc('scheduled_at_utc')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        $items = $targets->map(function (PostTarget $target) {
            $assets = $target->post->assets;
            $first = $assets->first();

            return new ProfileFeedItem(
                id: 'post-'.$target->post_id,
                mediaType: match (true) {
                    $assets->contains(fn (Asset $asset) => $asset->type === AssetType::Video) => 'VIDEO',
                    $assets->count() > 1 => 'CAROUSEL_ALBUM',
                    default => 'IMAGE',
                },
                thumbnailUrl: $first?->thumbnailUrl(),
                permalink: null,
                publishedAt: $target->scheduled_at_utc?->toIso8601String(),
            );
        })->values()->all();

        return new ProfileFeed(
            username: ltrim($account->handle, '@'),
            profilePictureUrl: null,
            mediaCount: null,
            items: array_values($items),
            source: ProfileFeed::SOURCE_APP,
        );
    }
}
