<?php

namespace App\Http\Controllers\Business;

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\ListClientPostsAction;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPostsTabRequest;
use App\Http\Requests\StorePostRequest;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Clients\ClientTabPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's posts list and "new post" entry. The editor itself is the
 * shared `posts.edit` (brand-agnostic), so this controller only lists, opens the create flow and
 * creates — each time resolving the implicit brand from {@see CurrentWorkspace} and calling the
 * same Actions/Form Requests the agency controllers use.
 */
class PostController extends Controller
{
    public function index(ClientPostsTabRequest $request, CurrentWorkspace $workspace, ListClientPostsAction $listPosts): Response
    {
        $brand = $workspace->brand();
        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('business/posts/index', [
            'posts' => $listPosts($brand, $filters)
                ->through(fn (Post $post) => ClientTabPresenter::post($post, $user)),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => array_map(
                    fn (PostStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                    PostStatus::cases(),
                ),
                'collections' => $brand->campaigns()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name]),
            ],
            'accounts' => $brand->socialAccounts()
                ->latest()
                ->get()
                ->map(fn (SocialAccount $account) => ClientTabPresenter::targetAccount($account)),
            'can' => [
                'create' => $user->can('create', [Post::class, $brand]),
            ],
        ]);
    }

    /**
     * The empty editor: create a blank draft and open it. Keeping create a POST (not a GET that
     * renders a form) means the brand always lands on the real editor with a persisted post.
     */
    public function create(Request $request, CurrentWorkspace $workspace, CreatePostAction $action): RedirectResponse
    {
        $brand = $workspace->brand();

        $this->authorize('create', [Post::class, $brand]);

        $post = $action($brand, ['status' => PostStatus::Draft->value]);

        return to_route('posts.edit', $post);
    }

    public function store(StorePostRequest $request, CurrentWorkspace $workspace, CreatePostAction $action): RedirectResponse
    {
        $post = $action($workspace->brand(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post created.')]);

        return to_route('posts.edit', $post);
    }
}
