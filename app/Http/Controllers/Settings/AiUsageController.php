<?php

namespace App\Http\Controllers\Settings;

use App\Actions\AI\Images\GetAiImageUsageAction;
use App\Actions\Organizations\UpdateAiImageQuotaAction;
use App\Actions\Organizations\UpdateAiSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiSettingsUpdateRequest;
use App\Http\Requests\Settings\AiUsageUpdateRequest;
use App\Services\AI\AiPerformanceService;
use App\Services\AI\AiUsageGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI usage (Owner only). Images vs quota and estimated cost (Step 16.11), plus the
 * Step M-7.1 control plane: the per-org AI enable toggle, this month's text tokens/requests/cost,
 * and the monthly token budget.
 */
class AiUsageController extends Controller
{
    public function edit(Request $request, GetAiImageUsageAction $imageUsage, AiUsageGuard $usageGuard, AiPerformanceService $performance): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/ai-usage', [
            'usage' => $imageUsage($organization),
            'textUsage' => $usageGuard->summary($organization),
            'performance' => $performance->forOrg($organization),
            'settings' => [
                'ai_enabled' => $organization->ai_enabled,
                'ai_monthly_token_budget' => $organization->ai_monthly_token_budget,
            ],
            // The platform flag + a configured vendor key. When false, the per-org toggle has no
            // effect (no provider is bound), so the UI can explain that rather than imply AI works.
            'aiConfigured' => (bool) config('ai.enabled', false) && (string) config('ai.deepseek.api_key', '') !== '',
            'aiEnabled' => (bool) config('ai.enabled', false),
        ]);
    }

    /**
     * Step 16.11: the monthly image quota.
     */
    public function update(AiUsageUpdateRequest $request, UpdateAiImageQuotaAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $quota = $request->validated('ai_monthly_image_quota');
        $action($organization, $request->user(), $quota === null ? null : (int) $quota);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image quota updated.')]);

        return to_route('ai-usage.edit');
    }

    /**
     * Step M-7.1: the AI enable toggle and monthly token budget.
     */
    public function updateSettings(AiSettingsUpdateRequest $request, UpdateAiSettingsAction $action): RedirectResponse
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        $budget = $request->validated('ai_monthly_token_budget');
        $action(
            $organization,
            $request->user(),
            (bool) $request->validated('ai_enabled'),
            $budget === null ? null : (int) $budget,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings updated.')]);

        return to_route('ai-usage.edit');
    }
}
