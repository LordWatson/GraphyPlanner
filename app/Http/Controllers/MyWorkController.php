<?php

namespace App\Http\Controllers;

use App\Actions\Posts\GetMyWorkAction;
use App\Models\Post;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-5.4 — "My work": the signed-in staff member's own posts across every client (assigned,
 * waiting on their review, due soon/overdue, mentioned). Staff only (a client reviewer is sent to
 * the portal); the Action returns each person's own records.
 */
class MyWorkController extends Controller
{
    public function index(GetMyWorkAction $myWork): Response
    {
        $user = request()->user();
        $this->authorize('viewHub', Post::class);

        return Inertia::render('my-work/index', [
            'buckets' => $myWork($user),
        ]);
    }
}
