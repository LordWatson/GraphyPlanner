<?php

namespace App\Http\Controllers\Settings;

use App\Actions\AI\Images\GetAiImageUsageAction;
use App\Actions\Organizations\UpdateAiImageQuotaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiUsageUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI usage (Step 16.11, Owner only): this month's images vs quota and estimated cost,
 * with an editable monthly quota.
 */
class AiUsageController extends Controller
{
    public function edit(Request $request, GetAiImageUsageAction $usage): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/ai-usage', [
            'usage' => $usage($organization),
            'aiEnabled' => (bool) config('ai.enabled', false),
        ]);
    }

    public function update(AiUsageUpdateRequest $request, UpdateAiImageQuotaAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $quota = $request->validated('ai_monthly_image_quota');
        $action($organization, $request->user(), $quota === null ? null : (int) $quota);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image quota updated.')]);

        return to_route('ai-usage.edit');
    }
}
