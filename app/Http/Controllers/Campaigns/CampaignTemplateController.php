<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CreateCampaignFromTemplateAction;
use App\Actions\Campaigns\CreateCampaignTemplateAction;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Client;
use App\Support\Campaigns\CampaignPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step C-3.4 — campaign templates: list, edit, delete, save a campaign as one, and start a
 * campaign from one. Org-scoped by `BelongsToOrganization` and the policy.
 */
class CampaignTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CampaignTemplate::class);

        return Inertia::render('campaigns/templates', [
            'templates' => CampaignTemplate::query()
                ->where('org_id', $request->user()->org_id)
                ->orderByDesc('is_starter')
                ->orderBy('name')
                ->get()
                ->map(fn (CampaignTemplate $template) => CampaignPresenter::templateSummary($template)),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name'])->map(fn (Client $client) => ['value' => (string) $client->id, 'label' => $client->name]),
            'platformOptions' => CampaignPresenter::platformOptions(),
        ]);
    }

    public function update(Request $request, CampaignTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $template->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        Log::info('Campaign template updated', ['template_id' => $template->id, 'user_id' => $request->user()->id]);

        return back();
    }

    public function destroy(Request $request, CampaignTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $template->delete();

        Log::info('Campaign template deleted', ['template_id' => $template->id, 'user_id' => $request->user()->id]);

        return back();
    }

    public function storeFromCampaign(Request $request, Campaign $campaign, CreateCampaignTemplateAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $this->authorize('viewAny', CampaignTemplate::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $action($campaign, $validated['name'], $validated['description'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved as a template. Start your next one from it.')]);

        return back();
    }

    public function use(Request $request, CampaignTemplate $template, CreateCampaignFromTemplateAction $action): RedirectResponse
    {
        $this->authorize('view', $template);

        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('org_id', $template->org_id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['distinct', Rule::enum(Platform::class)],
        ]);

        /** @var Client $client */
        $client = Client::query()->findOrFail($validated['client_id']);
        $this->authorize('create', [Campaign::class, $client]);

        $campaign = $action(
            $template,
            $client,
            CarbonImmutable::parse($validated['start_date']),
            $validated['name'],
            isset($validated['platforms']) ? array_values($validated['platforms']) : null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign ready. Its plan and brief are waiting for you.')]);

        return to_route('campaigns.plan', $campaign);
    }
}
