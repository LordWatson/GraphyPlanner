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

        // Named `settings`, not `workspace`: the latter is the shared shell prop
        // (`HandleInertiaRequests::sharedWorkspace`) the sidebar and copy read, so a page prop of the
        // same name would shadow it and flip the business shell back to the agency one.
        return Inertia::render('settings/workspace', [
            'settings' => [
                'name' => $organization->name,
                'require_post_review' => $organization->require_post_review,
                'insights_digest_opt_out' => $organization->insights_digest_opt_out,
            ],
        ]);
    }

    public function update(WorkspaceSettingsUpdateRequest $request, UpdateWorkspaceSettingsAction $action): RedirectResponse
    {
        $action(
            $request->user()->organization,
            $request->boolean('require_post_review'),
            $request->boolean('insights_digest_opt_out'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace settings saved.')]);

        return to_route('workspace-settings.edit');
    }
}
