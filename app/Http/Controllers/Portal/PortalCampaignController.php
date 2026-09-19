<?php

namespace App\Http\Controllers\Portal;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client portal's campaigns list/detail — a read-only counterpart to the internal
 * `CampaignController`, scoped to the logged-in contact's own `client_id` by
 * `EnsureClientPortalAccess`. Each campaign links through to the posts that were attached to it,
 * so a client contact can see everything published/scheduled under a given campaign.
 */
class PortalCampaignController extends Controller
{
    /**
     * Statuses a client contact is allowed to see, mirroring `PortalPostController` — pre-review
     * internal stages (idea, draft, internal review) never reach the portal.
     *
     * @var list<PostStatus>
     */
    private const VISIBLE_STATUSES = [
        PostStatus::WaitingClient,
        PostStatus::ChangesRequested,
        PostStatus::Approved,
        PostStatus::Scheduled,
        PostStatus::Publishing,
        PostStatus::Published,
        PostStatus::Failed,
        PostStatus::Archived,
    ];

    public function index(Request $request): Response
    {
        $campaigns = Campaign::query()
            ->where('client_id', $request->user()->client_id)
            ->withCount(['posts' => fn ($query) => $query->whereIn('status', self::VISIBLE_STATUSES)])
            ->latest()
            ->get()
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'status_label' => $campaign->status->label(),
                'start_date' => $campaign->start_date?->toDateString(),
                'end_date' => $campaign->end_date?->toDateString(),
                'goal' => $campaign->goal,
                'posts_count' => $campaign->posts_count,
            ]);

        return Inertia::render('portal/campaigns/index', [
            'client' => ['name' => $request->user()->client->name],
            'campaigns' => $campaigns,
        ]);
    }

    public function show(Campaign $campaign): Response
    {
        return Inertia::render('portal/campaigns/show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'status_label' => $campaign->status->label(),
                'start_date' => $campaign->start_date?->toDateString(),
                'end_date' => $campaign->end_date?->toDateString(),
                'goal' => $campaign->goal,
            ],
            'posts' => $campaign->posts()
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->with('targets.socialAccount')
                ->latest()
                ->get()
                ->map(fn (Post $post) => [
                    'id' => $post->id,
                    'status' => $post->status->value,
                    'status_label' => $post->status->label(),
                    'master_caption' => $post->master_caption,
                    'platform' => $post->targets->first()?->socialAccount?->platform?->value,
                    'handle' => $post->targets->first()?->socialAccount?->handle,
                ]),
        ]);
    }
}
