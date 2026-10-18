<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CreateCampaignCommentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\StoreCampaignCommentRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;

/**
 * Step C-5.3 — post to a campaign's conversation (studio and portal share this action).
 */
class CampaignCommentController extends Controller
{
    public function store(StoreCampaignCommentRequest $request, Campaign $campaign, CreateCampaignCommentAction $action): RedirectResponse
    {
        $action($campaign, $request->user(), $request->string('body')->trim()->toString(), $request->boolean('internal_only'));

        return back();
    }
}
