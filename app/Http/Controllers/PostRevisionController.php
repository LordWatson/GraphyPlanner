<?php

namespace App\Http\Controllers;

use App\Actions\Posts\RestorePostRevisionAction;
use App\Models\Post;
use App\Models\PostRevision;
use App\Services\Posts\PostRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Step M-5.3 — a post's content history: the editor's History drawer lists each version with a plain
 * "what changed" diff, and a version can be restored (which is itself a save). Loaded lazily (JSON)
 * so the editor doesn't carry every revision on each open.
 */
class PostRevisionController extends Controller
{
    public function index(Post $post, PostRevisionService $revisions): JsonResponse
    {
        $this->authorize('view', $post);

        $all = $post->revisions()->with('author:id,name')->orderBy('id')->get();

        $rows = $all->map(function (PostRevision $revision, int $index) use ($all, $revisions) {
            $previous = $index > 0 ? $all[$index - 1]->snapshot : [];

            return [
                'id' => $revision->id,
                'author' => $revision->author?->name,
                'shared' => $revision->shared_at !== null,
                'created_at' => $revision->created_at?->toIso8601String(),
                'diff' => $revisions->diff($previous, $revision->snapshot),
            ];
        });

        // Newest first for the drawer; the latest version is the current content.
        return response()->json(['revisions' => $rows->reverse()->values()]);
    }

    public function restore(Post $post, PostRevision $revision, RestorePostRevisionAction $action): RedirectResponse
    {
        $this->authorize('update', $post);
        abort_unless($revision->post_id === $post->id, 404);

        $action($post, $revision, request()->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restored this version.')]);

        return to_route('posts.edit', $post);
    }
}
