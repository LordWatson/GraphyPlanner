<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.3 — minimal scoped entry point for a single post inside the client portal, proving out
 * `EnsureClientPortalAccess`'s isolation guarantee. The full approvals/comments UI lands in
 * Step 6.5.
 */
class PortalPostController extends Controller
{
    public function show(Post $post): Response
    {
        return Inertia::render('portal/posts/show', [
            'post' => [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'master_caption' => $post->master_caption,
            ],
        ]);
    }
}
