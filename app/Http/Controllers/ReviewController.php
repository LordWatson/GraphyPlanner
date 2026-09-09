<?php

namespace App\Http\Controllers;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Http\Requests\ReviewDecisionRequest;
use App\Models\ReviewToken;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Step 0.13 client review portal (`/review/:token`): a light, unauthenticated view scoped
 * strictly to the token's client/post, with no money fields and only Approve / Request changes
 * actions. Access is granted purely by possession of a valid, unexpired, unrevoked `ReviewToken`
 * — never by a logged-in session — per spec §10/§12.
 */
class ReviewController extends Controller
{
    /**
     * Show the post waiting on this token's client for review.
     */
    public function show(string $token): Response
    {
        $post = $this->resolvePost($token);

        return Inertia::render('review/show', [
            'token' => $token,
            'client' => ['name' => $post->client->name],
            'post' => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'master_caption' => $post->master_caption,
                'hashtags' => $post->hashtags ?? [],
                'assets' => $post->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                ])->values(),
                'targets' => $post->targets->map(fn ($target) => [
                    'platform' => $target->socialAccount?->platform?->value,
                    'handle' => $target->socialAccount?->handle,
                    'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
                    'scheduled_local_time' => $target->scheduled_local_time,
                ])->values(),
                'can_decide' => $post->status === PostStatus::WaitingClient,
            ],
        ]);
    }

    /**
     * Approve or request changes on the post waiting on this token's client.
     */
    public function decide(
        ReviewDecisionRequest $request,
        string $token,
        TransitionPostStatusAction $action,
    ): RedirectResponse {
        $post = $this->resolvePost($token);

        $action(
            $post,
            PostStatus::from($request->validated('decision')),
            null,
            $request->validated('comment'),
        );

        return to_route('review.show', $token);
    }

    /**
     * Resolve the post scoped strictly to a valid token's `client_id` (and `post_id`, when the
     * token was issued for a single post) — never any other client's data (spec §12).
     */
    private function resolvePost(string $token): \App\Models\Post
    {
        $reviewToken = ReviewToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $reviewToken || ! $reviewToken->isValid()) {
            throw new NotFoundHttpException;
        }

        $post = $reviewToken->post_id
            ? $reviewToken->post()->with(['client', 'targets.socialAccount', 'assets'])->first()
            : $reviewToken->client->posts()
                ->with(['client', 'targets.socialAccount', 'assets'])
                ->where('status', PostStatus::WaitingClient)
                ->latest()
                ->first();

        if (! $post || $post->client_id !== $reviewToken->client_id) {
            throw new NotFoundHttpException;
        }

        return $post;
    }
}
