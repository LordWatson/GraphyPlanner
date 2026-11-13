<?php

namespace App\Http\Controllers;

use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\FindStoryHandoffForChannelAction;
use App\Actions\Stories\GetStoryHandoffMediaAction;
use App\Actions\Stories\GetStoryQueueAction;
use App\Actions\Stories\IssueStoryHandoffLinkAction;
use App\Actions\Stories\ReportStoryHandoffProblemAction;
use App\Actions\Stories\ShowStoryHandoffAction;
use App\Http\Requests\Stories\ReportStoryHandoffProblemRequest;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Scopes\TenantScopes;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase S-4 — the Story handoff page (`/story/:token`) and the signed-in ways into it.
 *
 * A Story with a link or stickers cannot be published through Instagram's API, so a person posts
 * it from the Instagram app. This is the page they do it from: the media, the extras to add, and
 * "I've posted it". The token routes are unauthenticated by design — the link is the
 * authorisation, exactly like the client review link — and every one of them goes through
 * `ShowStoryHandoffAction::resolve()`, which answers a plain 404 for a token that is unknown,
 * expired, cancelled or superseded.
 *
 * Nothing here logs or stores the token, and the page and its media are served `no-store`,
 * `noindex` and `no-referrer`.
 *
 * The token routes run with the tenant scopes suspended (`TenantScopes::suspended()`): the link
 * must do exactly the same thing for a guest, a member, and somebody whose browser happens to be
 * signed in to a different organization.
 */
class StoryHandoffController extends Controller
{
    /**
     * The link carries a token: nothing should keep, index or leak the page.
     */
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
    ];

    /**
     * The page. Also stamps the first view.
     */
    public function show(Request $request, string $token, ShowStoryHandoffAction $action, GetStoryQueueAction $queue): Response
    {
        $user = $request->user();

        [$handoff, $payload] = TenantScopes::suspended(function () use ($action, $token, $user): array {
            $handoff = $action->resolve($token);
            $action->markOpened($handoff);

            return [$handoff, $action->payload($handoff, $token, $user)];
        });

        $response = Inertia::render('story-handoff/show', [
            ...$payload,
            // Step S-4.4: a signed-in member with another story due can go straight to it. Their
            // own queue, so this one runs with the usual scopes on.
            'next' => $user !== null ? $queue->nextFor($user, $handoff) : null,
        ])->toResponse($request);

        foreach (self::PRIVATE_HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The file to share: the item the Story sends, cropped the way the auto lane crops it.
     */
    public function media(Request $request, string $token, ShowStoryHandoffAction $show, GetStoryHandoffMediaAction $action): Response
    {
        $media = TenantScopes::suspended(fn () => $action($show->resolve($token)));

        abort_if($media === null, 404);

        return $media->toResponse($request->headers->get('Range'));
    }

    /**
     * The poster tapped Share or Save media. Stops the nudge; never changes the channel.
     */
    public function shared(string $token, ShowStoryHandoffAction $action): Response
    {
        TenantScopes::suspended(fn () => $action->markShared($action->resolve($token)));

        return response()->noContent();
    }

    /**
     * "I've posted it." The channel is published through the pipeline's own outcome seam.
     */
    public function posted(Request $request, string $token, ShowStoryHandoffAction $show, CompleteStoryHandoffAction $action): RedirectResponse
    {
        TenantScopes::suspended(function () use ($request, $token, $show, $action): void {
            $handoff = $show->resolve($token);

            $action($handoff, $this->memberOf($handoff, $request), StoryHandoff::VIA_LINK);
        });

        return to_route('story-handoff.show', ['token' => $token]);
    }

    /**
     * "Something went wrong." The channel fails with the poster's note and the team is told.
     */
    public function problem(
        ReportStoryHandoffProblemRequest $request,
        string $token,
        ShowStoryHandoffAction $show,
        ReportStoryHandoffProblemAction $action,
    ): RedirectResponse {
        TenantScopes::suspended(function () use ($request, $token, $show, $action): void {
            $handoff = $show->resolve($token);

            $action($handoff, (string) $request->validated('note'), $this->memberOf($handoff, $request));
        });

        return to_route('story-handoff.show', ['token' => $token]);
    }

    /**
     * Who to record against a token action: the signed-in member, but only when they belong to
     * the handoff's own organization. Anybody else holding the link is, to this post, a guest.
     */
    private function memberOf(StoryHandoff $handoff, Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && $user->org_id === $handoff->org_id ? $user : null;
    }

    /**
     * Step S-4.5 — a member opens a channel's handoff from inside Alima (the bell, Needs attention,
     * the editor). Works out the handoff's link and redirects to the page; when there is nothing to open
     * yet, says why. This is the URL the bell stores, so no token is ever written to a
     * notification row.
     */
    public function open(
        Request $request,
        Post $post,
        PostTarget $target,
        FindStoryHandoffForChannelAction $find,
        IssueStoryHandoffLinkAction $issueLink,
    ): RedirectResponse|InertiaResponse {
        $this->authorize('postStoryByHand', $post);

        ['state' => $state, 'handoff' => $handoff] = $find($target->setRelation('post', $post));

        if ($state === FindStoryHandoffForChannelAction::OPEN && $handoff !== null) {
            return redirect()->to($issueLink($handoff));
        }

        $target->loadMissing('socialAccount');
        $account = $target->socialAccount;
        $timezone = $account->timezone ?? (string) config('app.timezone');
        $due = $target->scheduled_at_utc?->copy()->setTimezone($timezone);

        return Inertia::render('story-handoff/not-ready', [
            'state' => $state,
            'account' => ['handle' => $account?->handle, 'platform_label' => $account?->platform->label()],
            'due' => $due === null ? null : ['date' => $due->toDateString(), 'time' => $due->format('H:i'), 'timezone' => $timezone],
            'postUrl' => route('posts.edit', $post->id),
        ]);
    }

    /**
     * Step S-5.2 — "Mark as posted" from the editor: a member confirms a Story went out without
     * opening the handoff page, including one whose window has already passed.
     */
    public function markPosted(
        Request $request,
        Post $post,
        PostTarget $target,
        FindStoryHandoffForChannelAction $find,
        CompleteStoryHandoffAction $action,
    ): RedirectResponse {
        $this->authorize('postStoryByHand', $post);

        ['handoff' => $handoff] = $find($target->setRelation('post', $post));

        if ($handoff === null) {
            throw ValidationException::withMessages([
                'handoff' => __('This story isn’t waiting to be posted by hand.'),
            ]);
        }

        $action($handoff, $request->user(), StoryHandoff::VIA_STAFF);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as posted.')]);

        return to_route('posts.edit', $post);
    }

    /**
     * Step S-4.4 — the poster's queue: every story the signed-in member is due to post.
     */
    public function queue(Request $request, GetStoryQueueAction $queue): InertiaResponse
    {
        $this->authorize('viewStoryQueue', Post::class);

        return Inertia::render('stories/to-post', [
            'stories' => $queue($request->user()),
        ]);
    }
}
