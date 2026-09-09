<?php

namespace App\Http\Controllers;

use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the Home / needs-attention dashboard (Step 0.12): failed publishes, posts waiting on
     * client approval, posts missing required media, and disconnected social accounts, each row
     * linking back to the relevant post (or client, for account rows).
     */
    public function index(Request $request, GetNeedsAttentionItemsAction $getNeedsAttentionItems): Response
    {
        $this->authorize('viewHome', Post::class);

        return Inertia::render('home', [
            'items' => $getNeedsAttentionItems($request->user()),
        ]);
    }
}
