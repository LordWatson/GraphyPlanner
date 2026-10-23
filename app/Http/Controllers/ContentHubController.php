<?php

namespace App\Http\Controllers;

use App\Actions\Posts\BulkUpdatePostsAction;
use App\Actions\Posts\GetContentBoardAction;
use App\Actions\Posts\ListPostsAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Requests\BulkUpdatePostsRequest;
use App\Http\Requests\ContentHubRequest;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Post;
use App\Models\SavedPostView;
use App\Models\User;
use App\Support\Posts\ContentHubPresenter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step M-5.1 — the content hub (`/content`): every post across every client, as a filterable table
 * or a status board, with a bulk-action bar and saved filter views. The agency equivalent of the
 * business shell's `/posts` list. Thin: it validates the query, calls the list/board/bulk Actions
 * and renders. Each bulk move is re-validated per post inside the Action.
 */
class ContentHubController extends Controller
{
    public function index(ContentHubRequest $request, ListPostsAction $listPosts, GetContentBoardAction $board): Response
    {
        $user = $request->user();
        $filters = $request->filters();
        $view = $request->view();

        return Inertia::render('content/index', [
            'view' => $view,
            'filters' => $filters,
            'posts' => $view === 'table'
                ? $listPosts($filters)->through(fn (Post $post) => ContentHubPresenter::row($post, $user))
                : null,
            'board' => $view === 'board' ? $board($filters, $user) : null,
            'filterOptions' => $this->filterOptions(),
            'savedViews' => SavedPostView::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'filters'])
                ->all(),
            'can' => [
                // Step M-5.2: the producing roles can start a post from the hub (per-client create
                // is re-checked by `StorePostRequest`).
                'create' => in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true),
            ],
        ]);
    }

    public function bulk(BulkUpdatePostsRequest $request, BulkUpdatePostsAction $action): JsonResponse
    {
        $result = $action(
            $request->user(),
            $request->string('action')->toString(),
            array_values(array_map('intval', $request->array('post_ids'))),
            $request->value(),
        );

        return response()->json($result);
    }

    public function export(ContentHubRequest $request, ListPostsAction $listPosts): StreamedResponse
    {
        $filters = $request->filters();
        $filename = 'content-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($listPosts, $filters) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Client', 'Campaign', 'Status', 'Caption', 'Platforms', 'Scheduled date', 'Scheduled time', 'Owner', 'Updated']);

            $listPosts->query($filters)
                ->with(['client:id,name', 'campaign:id,name', 'creator:id,name', 'targets.socialAccount'])
                ->orderByDesc('updated_at')
                ->chunk(200, function ($posts) use ($handle) {
                    foreach ($posts as $post) {
                        $first = $post->targets->sortBy(fn ($t) => $t->scheduled_at_utc?->getTimestamp() ?? PHP_INT_MAX)->first();

                        fputcsv($handle, [
                            $post->client?->name,
                            $post->campaign?->name,
                            $post->status->label(),
                            $post->master_caption,
                            $post->targets->map(fn ($t) => $t->socialAccount?->platform?->value)->filter()->unique()->implode(', '),
                            $first?->scheduled_local_date?->toDateString(),
                            $first?->scheduled_local_time,
                            $post->creator?->name,
                            $post->updated_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'statuses' => array_map(
                fn (PostStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                PostStatus::cases(),
            ),
            'platforms' => array_map(
                fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()],
                Platform::cases(),
            ),
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['value' => $client->id, 'label' => $client->name])
                ->all(),
            'campaigns' => Campaign::query()
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Campaign $campaign) => ['value' => $campaign->id, 'label' => $campaign->name, 'client_id' => $campaign->client_id])
                ->all(),
            // Step M-5.4: internal staff for the assignee filter and bulk-assign.
            'staff' => User::query()
                ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $member) => ['value' => $member->id, 'label' => $member->name])
                ->all(),
        ];
    }
}
