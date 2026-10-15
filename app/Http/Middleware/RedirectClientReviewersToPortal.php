<?php

namespace App\Http\Middleware;

use App\Enums\Role;
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
            'posts.edit' => $this->redirectForPost($request->route('post'), $user->client_id),
            default => to_route('portal.dashboard'),
        };
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
