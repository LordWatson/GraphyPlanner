<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Workspace\UpdateWorkspaceSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\WorkspaceSettingsUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-3.3 — the business workspace's own settings page. The route group is `workspace:business`,
 * so an agency never reaches it; the Owner check is in the request/policy.
 */
class WorkspaceController extends Controller
{
    public function edit(Request $request): Response
    {
        $organization = $request->user()->organization;

        $this->authorize('update', $organization);

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'name' => $organization->name,
                'require_post_review' => $organization->require_post_review,
            ],
        ]);
    }

    public function update(WorkspaceSettingsUpdateRequest $request, UpdateWorkspaceSettingsAction $action): RedirectResponse
    {
        $action($request->user()->organization, $request->boolean('require_post_review'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace settings saved.')]);

        return to_route('workspace-settings.edit');
    }
}
