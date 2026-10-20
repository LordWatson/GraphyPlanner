<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\BuildCampaignWorkspaceHeaderAction;
use App\Actions\Campaigns\GetCampaignBoardAction;
use App\Actions\Campaigns\GetCampaignOverviewAction;
use App\Actions\Campaigns\GetCampaignPlanAction;
use App\Actions\Campaigns\GetCampaignResultsAction;
use App\Actions\Campaigns\ListCampaignActivityAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\CampaignBriefRevision;
use App\Models\CampaignComment;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\User;
use App\Services\Grids\GridPlanService;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Grids\GridLibrary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step C-1.2 — the campaign workspace: one route per tab (the I-2.4 client-tab pattern), so deep
 * links and the back button work and each tab loads only its own data. Every tab shares the
 * header props from `BuildCampaignWorkspaceHeaderAction`; every tab is viewable by any staff role
 * that can see the campaign, with edit actions gated on the `can` props.
 */
class CampaignWorkspaceController extends Controller
{
    public const ASSETS_PER_PAGE = 48;

    public function __construct(private readonly BuildCampaignWorkspaceHeaderAction $header) {}

    public function show(Request $request, Campaign $campaign, GetCampaignOverviewAction $overview, ListCampaignActivityAction $activity, GridPlanService $grids): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/overview', [
            ...($this->header)($campaign, $request->user(), 'overview'),
            ...$overview($campaign),
            'comments' => Inertia::defer(fn () => $this->comments($campaign)),
            'recentActivity' => Inertia::defer(fn () => $activity($campaign, 6)->items()),
            // Phase 18: the campaign's Instagram grid plans.
            'grids' => Inertia::defer(fn () => GridPlan::query()
                ->where('campaign_id', $campaign->id)
                ->with(['socialAccount', 'campaign', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets'])
                ->latest('updated_at')
                ->get()
                ->map(fn (GridPlan $plan) => GridLibrary::summary($plan, $grids))
                ->values()
                ->all()),
            // Step C-5.3: only staff can be @-mentioned.
            'mentionables' => User::query()
                ->where('org_id', $campaign->org_id)
                ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
                ->whereKeyNot($request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
        ]);
    }

    public function brief(Request $request, Campaign $campaign): Response
    {
        $this->authorize('view', $campaign);
        $brief = $campaign->brief;
        $brandBrain = $campaign->client->brandBrain;

        return Inertia::render('campaigns/brief', [
            ...($this->header)($campaign, $request->user(), 'brief'),
            'brief' => $brief === null ? null : [
                ...$brief->content(),
                'status' => $brief->status->value,
                'status_label' => $brief->status->label(),
                'approved_at' => $brief->approved_at?->toIso8601String(),
                'approver' => $brief->approver?->name,
                'updated_at' => $brief->updated_at?->toIso8601String(),
            ],
            'revisions' => $brief === null ? [] : $brief->revisions()
                ->with(['decider:id,name', 'sender:id,name'])
                ->orderByDesc('number')
                ->get()
                ->map(fn (CampaignBriefRevision $revision) => [
                    'id' => $revision->id,
                    'number' => $revision->number,
                    'sent_at' => $revision->sent_at->toIso8601String(),
                    'sent_by' => $revision->sender?->name,
                    'decision' => $revision->decision?->value,
                    'decision_comment' => $revision->decision_comment,
                    'decided_by' => $revision->decider?->name,
                    'decided_at' => $revision->decided_at?->toIso8601String(),
                    'content_hash' => $revision->content_hash,
                ])
                ->all(),
            // Brand Brain guardrails merged into the brief's "Avoid" hints (Step C-2.1).
            'brandAvoid' => array_values(array_unique([
                ...($brandBrain->voice['do_nots'] ?? []),
                ...($brandBrain->hashtag_policy['never_use'] ?? []),
            ])),
            'hasPortalContacts' => User::query()->where('client_id', $campaign->client_id)->where('role', Role::ClientReviewer)->exists(),
        ]);
    }

    public function plan(Request $request, Campaign $campaign, GetCampaignPlanAction $plan): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/plan', [
            ...($this->header)($campaign, $request->user(), 'plan'),
            ...$plan($campaign),
        ]);
    }

    public function board(Request $request, Campaign $campaign, GetCampaignBoardAction $board): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/board', [
            ...($this->header)($campaign, $request->user(), 'board'),
            ...$board($campaign, $request->user()),
        ]);
    }

    public function assets(Request $request, Campaign $campaign): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/assets', [
            ...($this->header)($campaign, $request->user(), 'assets'),
            'assets' => $campaign->assets()
                ->latest()
                ->latest('id')
                ->paginate(self::ASSETS_PER_PAGE)
                ->through(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'thumbnail_url' => $asset->thumbnailUrl(),
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                    'mime_type' => $asset->mime_type,
                    'source' => $asset->source->value,
                    'is_ai_generated' => (bool) $asset->is_ai_generated,
                    'created_at' => $asset->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function approvals(Request $request, Campaign $campaign): Response
    {
        $this->authorize('view', $campaign);
        $user = $request->user();

        $posts = $campaign->posts()
            ->whereIn('status', [PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::ChangesRequested, PostStatus::Draft])
            ->with(['targets.socialAccount', 'deliverable'])
            ->orderBy('updated_at')
            ->get();

        return Inertia::render('campaigns/approvals', [
            ...($this->header)($campaign, $user, 'approvals'),
            'groups' => collect([PostStatus::ChangesRequested, PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::Draft])
                ->map(fn (PostStatus $status) => [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'posts' => $posts->where('status', $status)->map(fn (Post $post) => [
                        ...CampaignPresenter::post($post),
                        'can' => [
                            'submit' => $user->can('transition', [$post, PostStatus::InternalReview]),
                            'sendToClient' => $user->can('transition', [$post, PostStatus::WaitingClient]),
                            'approve' => $user->can('transition', [$post, PostStatus::Approved]),
                        ],
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
            'brief' => $campaign->brief ? [
                'status' => $campaign->brief->status->value,
                'status_label' => $campaign->brief->status->label(),
            ] : null,
        ]);
    }

    public function results(Request $request, Campaign $campaign, GetCampaignResultsAction $results): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/results', [
            ...($this->header)($campaign, $request->user(), 'results'),
            ...$results($campaign, $request->user()),
        ]);
    }

    public function activity(Request $request, Campaign $campaign, ListCampaignActivityAction $activity): Response
    {
        $this->authorize('view', $campaign);

        return Inertia::render('campaigns/activity', [
            ...($this->header)($campaign, $request->user(), 'activity'),
            'activity' => $activity($campaign),
        ]);
    }

    /**
     * Step C-5.3 — the campaign conversation, oldest first (the last 100). Staff see internal notes.
     *
     * @return list<array<string, mixed>>
     */
    private function comments(Campaign $campaign): array
    {
        return array_values($campaign->comments()
            ->with('user:id,name,role')
            ->latest()
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->map(fn (CampaignComment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'internal_only' => $comment->internal_only,
                'author' => $comment->user->name ?? 'Someone',
                'is_client' => $comment->user?->role === Role::ClientReviewer,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
