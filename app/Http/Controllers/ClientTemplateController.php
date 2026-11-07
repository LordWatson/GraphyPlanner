<?php

namespace App\Http\Controllers;

use App\Actions\ClientTemplates\SaveClientAsTemplateAction;
use App\Models\Client;
use App\Models\ClientTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client onboarding templates: capturing one from a working client, listing them, removing one.
 *
 * Authorised against `Client` rather than a policy of its own — a template is a set of client
 * settings, so whoever may create and update clients may manage the templates those settings come
 * from. A second policy saying the same thing in different words is a second thing to keep in step.
 */
class ClientTemplateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/templates', [
            'templates' => ClientTemplate::query()
                ->with('creator:id,name')
                ->orderByDesc('is_built_in')
                ->orderBy('name')
                ->get()
                ->map(fn (ClientTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'description' => $template->description,
                    'summary' => $template->summary(),
                    'is_built_in' => $template->is_built_in,
                    'created_by' => $template->creator?->name,
                    'created_at' => $template->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request, Client $client, SaveClientAsTemplateAction $action): RedirectResponse
    {
        // Reading this client's settings and writing an org-level template: both are things a
        // person who may update the client may do.
        $this->authorize('update', $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $template = $action($client, $request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved ":name" as a template.', ['name' => $template->name])]);

        return back();
    }

    public function destroy(Request $request, ClientTemplate $template): RedirectResponse
    {
        $this->authorize('create', Client::class);

        // `BelongsToOrganization` resolves a bound model **without** the org scope on purpose, so
        // that a policy can answer 403 rather than a 404 hiding whether the record exists. That
        // means the org check has to happen here: authorising against the `Client` *class* says
        // "may this person manage templates", not "is this template theirs", and without this line
        // another organization's template was deletable by id. `CrossTenantRouteTest` caught it.
        abort_unless($template->org_id === $request->user()->org_id, 403);

        // Built-ins are rows, not config, precisely so that deleting one makes it stay deleted.
        $template->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template removed.')]);

        return to_route('client-templates.index');
    }
}
