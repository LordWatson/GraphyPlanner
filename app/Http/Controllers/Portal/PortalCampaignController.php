<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Campaigns\BatchReviewCampaignPostsAction;
use App\Actions\Campaigns\DecideCampaignBriefAction;
use App\Actions\Campaigns\GenerateCampaignBriefPdfAction;
use App\Enums\ApprovalDecision;
use App\Enums\BriefStatus;
use App\Enums\CampaignReportStatus;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DecideCampaignBriefRequest;
use App\Http\Requests\Portal\SubmitCampaignReviewRequest;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\CampaignComment;
use App\Models\CampaignPhase;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Posts\PostMediaPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client portal's campaigns — scoped to the contact's own `client_id` by
 * `EnsureClientPortalAccess` (a bound `{campaign}` from another client is a 404).
 *
 * Step C-5.1: everything here goes through `CampaignPresenter::forPortal()` / `portalPost()`
 * (allowlists). Only posts from "Waiting on client" onward, never deliverables, billing, internal
 * notes, health or readiness. The brief is shown only once sent, and always as the frozen revision
 * the studio sent — never the working copy.
 */
class PortalCampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = CampaignClock::today($user->organization);

        $campaigns = Campaign::query()
            ->where('client_id', $user->client_id)
            ->where('status', '!=', 'archived')
            ->withCount([
                'posts' => fn ($query) => $query->whereIn('status', PostStatus::clientVisible()),
                'posts as awaiting_count' => fn ($query) => $query->where('status', PostStatus::WaitingClient),
            ])
            ->with('brief:id,campaign_id,status')
            ->orderByRaw('start_date is null')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (Campaign $campaign) => [
                ...CampaignPresenter::forPortal($campaign, $today),
                'posts_count' => (int) $campaign->getAttribute('posts_count'),
                'awaiting_count' => (int) $campaign->getAttribute('awaiting_count'),
                'brief_waiting' => $campaign->brief?->status === BriefStatus::Sent,
            ]);

        return Inertia::render('portal/campaigns/index', [
            'client' => ['name' => $user->client->name],
            'campaigns' => $campaigns,
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        $today = CampaignClock::today($campaign);

        $posts = $campaign->posts()
            ->whereIn('status', PostStatus::clientVisible())
            ->where('status', '!=', PostStatus::Archived)
            ->with(['targets.socialAccount', 'deliverable'])
            ->get()
            ->sortBy(fn (Post $post) => $post->targets->map(fn ($target) => $target->scheduled_at_utc?->getTimestamp())->filter()->min() ?? PHP_INT_MAX)
            ->values();

        $report = $campaign->report;

        return Inertia::render('portal/campaigns/show', [
            'campaign' => CampaignPresenter::forPortal($campaign, $today),
            'brief' => $this->brief($campaign),
            'phases' => $campaign->phases()->get()->map(fn (CampaignPhase $phase) => [
                'id' => $phase->id,
                'name' => $phase->name,
                'starts_on' => $phase->starts_on?->toDateString(),
                'ends_on' => $phase->ends_on?->toDateString(),
                'colour' => $phase->colour?->value,
            ])->all(),
            'posts' => $posts->map(fn (Post $post) => CampaignPresenter::portalPost($post))->all(),
            'needsReview' => $posts->where('status', PostStatus::WaitingClient)->count(),
            'comments' => $campaign->comments()
                ->where('internal_only', false)
                ->with('user:id,name,role')
                ->oldest()
                ->oldest('id')
                ->limit(100)
                ->get()
                ->map(fn (CampaignComment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'author' => $comment->user->name ?? 'Someone',
                    'is_client' => $comment->user?->role?->value === 'client_reviewer',
                    'created_at' => $comment->created_at?->toIso8601String(),
                ])
                ->all(),
            'report' => $report !== null && $report->status === CampaignReportStatus::Shared ? [
                'summary' => $report->summary,
                'highlights' => $report->highlights ?? [],
                'learnings' => $report->learnings,
                'next_steps' => $report->next_steps,
                'kpi_snapshot' => $report->kpi_snapshot ?? [],
                'engagement_snapshot' => $report->engagement_snapshot,
                'shared_at' => $report->shared_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Step C-2.3 — approve the brief or ask for changes (a comment is required for changes).
     */
    public function decideBrief(DecideCampaignBriefRequest $request, Campaign $campaign, DecideCampaignBriefAction $action): RedirectResponse
    {
        $decision = ApprovalDecision::from($request->string('decision')->toString());

        $action(
            $campaign,
            $request->integer('revision_id'),
            $request->string('content_hash')->toString(),
            $decision,
            $request->string('comment')->trim()->toString() ?: null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $decision === ApprovalDecision::Approved
            ? __('Brief approved. Thank you, we’re on it.')
            : __('Thanks. We’ll update the brief and send it back.')]);

        return back();
    }

    /**
     * Step C-5.2 — "Review this campaign": every post waiting on the client, in going-out order,
     * with what's needed to preview it. `seen_at` is the revision marker sent back with each
     * decision so a post the studio changed mid-review is never approved blind.
     */
    public function review(Campaign $campaign): Response
    {
        $posts = $campaign->posts()
            ->where('status', PostStatus::WaitingClient)
            ->with(['targets.socialAccount', 'assets'])
            ->get()
            ->sortBy(fn (Post $post) => $post->targets->map(fn ($target) => $target->scheduled_at_utc?->getTimestamp())->filter()->min() ?? PHP_INT_MAX)
            ->values();

        return Inertia::render('portal/campaigns/review', [
            'campaign' => CampaignPresenter::forPortal($campaign, CampaignClock::today($campaign)),
            'posts' => $posts->map(fn (Post $post) => [
                'id' => $post->id,
                'master_caption' => $post->master_caption,
                'review_message' => $post->review_message,
                'hashtags' => $post->hashtags ?? [],
                'seen_at' => $post->updated_at?->toIso8601String(),
                'music' => $post->music,
                'location' => $post->location,
                'media_format' => PostMediaPresenter::mediaFormat($post),
                'assets' => $post->assets->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'thumbnail_url' => $asset->thumbnailUrl(),
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                    ...PostMediaPresenter::framing($asset),
                ])->values()->all(),
                'targets' => $post->targets->map(fn (PostTarget $target) => [
                    'id' => $target->id,
                    'platform' => $target->socialAccount?->platform->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->scheduled_local_time,
                    ...PostMediaPresenter::channelPreview($target->setRelation('post', $post)),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    public function submitReview(SubmitCampaignReviewRequest $request, Campaign $campaign, BatchReviewCampaignPostsAction $action): RedirectResponse
    {
        $result = $action($campaign, $request->user(), $request->decisions(), $request->string('comment')->trim()->toString() ?: null);

        $done = count($result['approved']) + count($result['changes_requested']);
        $message = $done === 0
            ? __('Nothing was recorded. The studio changed those posts while you were reviewing.')
            : trans_choice('{1} Thanks. 1 decision sent to the studio.|[2,*] Thanks. :count decisions sent to the studio.', $done, ['count' => $done]);

        if ($result['stale'] !== []) {
            $message .= ' '.trans_choice('{1} 1 post changed while you were looking, so take another look at it.|[2,*] :count posts changed while you were looking, so take another look at them.', count($result['stale']), ['count' => count($result['stale'])]);
        }

        Inertia::flash('toast', ['type' => $result['stale'] === [] ? 'success' : 'warning', 'message' => $message]);

        return $result['stale'] === []
            ? to_route('portal.campaigns.show', $campaign)
            : to_route('portal.campaigns.review', $campaign);
    }

    public function briefPdf(Campaign $campaign, GenerateCampaignBriefPdfAction $action): HttpResponse
    {
        abort_unless(in_array($campaign->brief?->status, [BriefStatus::Sent, BriefStatus::Approved, BriefStatus::ChangesRequested], true), 404);

        return response($action($campaign), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($campaign->name).'-brief.pdf"',
        ]);
    }

    /**
     * The latest *sent* revision and its decision state, or null while the brief is a draft the
     * client has never been sent.
     *
     * @return array<string, mixed>|null
     */
    private function brief(Campaign $campaign): ?array
    {
        $brief = $campaign->brief;
        $revision = $brief?->revisions()->orderByDesc('number')->first();

        if ($brief === null || $revision === null) {
            return null;
        }

        return [
            'status' => $brief->status === BriefStatus::Draft ? 'updating' : $brief->status->value,
            'revision_id' => $revision->id,
            'number' => $revision->number,
            'content_hash' => $revision->content_hash,
            'content' => $revision->content,
            'sent_at' => $revision->sent_at->toIso8601String(),
            'decision' => $revision->decision?->value,
            'decided_at' => $revision->decided_at?->toIso8601String(),
            'can_decide' => $brief->status === BriefStatus::Sent && $revision->decision === null,
        ];
    }
}
