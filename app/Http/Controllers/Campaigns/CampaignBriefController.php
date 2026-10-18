<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\GenerateCampaignBriefPdfAction;
use App\Actions\Campaigns\SendCampaignBriefAction;
use App\Actions\Campaigns\UpdateCampaignBriefAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\UpdateCampaignBriefRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Step C-2.1 / C-2.3 — edit the brief, send it to the client, download it as a PDF.
 */
class CampaignBriefController extends Controller
{
    public function update(UpdateCampaignBriefRequest $request, Campaign $campaign, UpdateCampaignBriefAction $action): RedirectResponse
    {
        $brief = $action($campaign, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $brief->wasChanged('status')
            ? __('Brief saved. It’s back to draft, so send it again for sign-off.')
            : __('Brief saved.')]);

        return back();
    }

    public function send(Request $request, Campaign $campaign, SendCampaignBriefAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $revision = $action($campaign, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Revision :number sent to the client.', ['number' => $revision->number])]);

        return back();
    }

    public function pdf(Request $request, Campaign $campaign, GenerateCampaignBriefPdfAction $action): Response
    {
        $this->authorize('view', $campaign);

        return response($action($campaign), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($campaign->name).'-brief.pdf"',
        ]);
    }
}
