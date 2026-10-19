<?php

namespace App\Actions\Campaigns;

use App\Enums\PostStatus;
use App\Models\Asset;
use App\Models\CampaignShareLink;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Campaigns enhancement C-5.4 — what a preview link shows: the campaign's identity and its posts
 * from "Approved" onward as a visual grid. No decisions, no comments, no internal fields. An
 * unknown, expired or revoked token is a 404 (never "this link expired", which confirms it existed
 * to someone guessing).
 */
class ShowCampaignShareLinkAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $token): array
    {
        $link = CampaignShareLink::query()
            ->where('token_hash', CampaignShareLink::hash($token))
            ->with(['campaign' => fn ($query) => $query->with(['client:id,name', 'organization:id,name,default_timezone'])])
            ->first();

        if ($link === null || ! $link->isValid() || $link->campaign === null) {
            Log::info('Campaign share link refused', ['share_link_id' => $link?->id]);

            throw new NotFoundHttpException;
        }

        CampaignShareLink::query()->whereKey($link->id)->update(['last_viewed_at' => now(), 'view_count' => $link->view_count + 1]);

        $campaign = $link->campaign;
        $posts = Post::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', PostStatus::approvedOrLater())
            ->with(['targets.socialAccount', 'assets', 'deliverable'])
            ->get()
            ->sortBy(fn (Post $post) => $post->targets->map(fn ($target) => $target->scheduled_at_utc?->getTimestamp())->filter()->min() ?? PHP_INT_MAX)
            ->values();

        return [
            'campaign' => CampaignPresenter::forPortal($campaign, CampaignClock::today($campaign)),
            'client' => ['name' => $campaign->client?->name],
            'agency' => ['name' => $campaign->organization?->name],
            'expires_at' => $link->expires_at->toIso8601String(),
            'posts' => $posts->map(fn (Post $post) => [
                ...CampaignPresenter::portalPost($post),
                'assets' => $post->assets->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'thumbnail_url' => $asset->thumbnailUrl(),
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                    'source' => $asset->source->value,
                    'mime_type' => $asset->mime_type,
                ])->values()->all(),
            ])->all(),
        ];
    }
}
