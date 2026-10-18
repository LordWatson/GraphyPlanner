<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionPostRequest;
use App\Models\Campaign;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Step C-4.3 — a drag on the campaign board. Validated by the same `TransitionPostRequest` the
 * post editor uses (state machine, role, pre-schedule checklist), executed by the same
 * `TransitionPostStatusAction`, then back to the board instead of the editor. The post must belong
 * to the campaign (scoped binding).
 */
class CampaignBoardController extends Controller
{
    public function move(TransitionPostRequest $request, Campaign $campaign, Post $post, TransitionPostStatusAction $action): RedirectResponse
    {
        $to = PostStatus::from($request->validated('to'));
        $action($post, $to, $request->user(), $request->validated('comment'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moved to :status.', ['status' => strtolower($to->label())])]);

        return back();
    }
}
