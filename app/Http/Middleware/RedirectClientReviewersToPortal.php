<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\GridPlan;
use App\Models\Post;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client portal contacts (`Role::ClientReviewer`) live entirely in `/portal/*`: applied to the
 * internal routes they could otherwise reach (dashboard, calendar, needs-attention, the post
 * editor), this sends them to the portal equivalent instead of the internal UI. That keeps
 * internal-only data (internal comments, activity logs, pre-review drafts, org-wide stats) and
 * internal-only links (which would 403) out of their experience entirely. Every other role
 * passes straight through.
 */
class RedirectClientReviewersToPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== Role::ClientReviewer) {
            return $next($request);
        }

        return match ($request->route()?->getName()) {
            'calendar' => to_route('portal.calendar', $request->only(['campaign_id', 'status', 'platform'])),
            // Any post-scoped internal route lands the reviewer on that post in their portal (or the
            // portal posts list when it isn't theirs / not yet client-visible). Step M-6.2.
            'posts.edit', 'posts.transition', 'posts.comments.store' => $this->redirectForPost($request->route('post'), $user->client_id),
            // Step C-1.1/C-1.2: the campaigns hub and workspace have portal counterparts.
            'campaigns.index', 'campaigns.compare' => to_route('portal.campaigns.index'),
            'campaigns.show' => $this->redirectForCampaign($request->route('campaign'), $user->client_id),
            // Phase 18: the grid planner's portal counterpart.
            'grids.edit' => $this->redirectForGrid($request->route('grid'), $user->client_id),
            'grids.index' => to_route('portal.grids.index'),
            default => to_route('portal.dashboard'),
        };
    }

    private function redirectForCampaign(mixed $campaign, ?int $clientId): Response
    {
        if ($campaign instanceof Campaign && $campaign->client_id === $clientId) {
            return to_route('portal.campaigns.show', $campaign);
        }

        return to_route('portal.campaigns.index');
    }

    private function redirectForGrid(mixed $grid, ?int $clientId): Response
    {
        if ($grid instanceof GridPlan && $grid->client_id === $clientId && $grid->status->isClientVisible()) {
            return to_route('portal.grids.show', $grid);
        }

        return to_route('portal.grids.index');
    }

    /**
     * Deep-link to the post's portal page when the contact may see it there; otherwise fall back
     * to the portal posts list rather than revealing (or 404-ing on) an internal-only post.
     */
    private function redirectForPost(mixed $post, ?int $clientId): Response
    {
        if ($post instanceof Post && $post->client_id === $clientId && $post->status->isClientVisible()) {
            return to_route('portal.posts.show', $post);
        }

        return to_route('portal.posts.index');
    }
}
