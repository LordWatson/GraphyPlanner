<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\ListCampaignsAction;
use App\Contracts\CampaignFeatures;
use App\Enums\CampaignFeature;
use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CampaignHubRequest;
use App\Models\Campaign;
use App\Models\Client;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step C-1.1 — `GET /campaigns`: every campaign across clients, as a filtered, paged list or a
 * 90-day timeline. Client contacts are redirected to the portal by `portal.redirect`.
 */
class CampaignIndexController extends Controller
{
    public function __invoke(CampaignHubRequest $request, ListCampaignsAction $list, CampaignFeatures $features): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $filters = $request->filters();
        $view = $request->view();

        $props = [
            'view' => $view,
            'filters' => [...$filters, 'view' => $view],
            'stats' => Inertia::defer(fn () => $list->stats($organization)),
            'filterOptions' => [
                'clients' => Client::query()->orderBy('name')->get(['id', 'name'])->map(fn (Client $client) => ['value' => (string) $client->id, 'label' => $client->name]),
                'statuses' => array_map(fn (CampaignStatus $status) => ['value' => $status->value, 'label' => $status->label()], CampaignStatus::cases()),
            ],
            'can' => [
                'create' => in_array($user->role, [Role::Owner, Role::Strategist], true),
                'templates' => in_array($user->role, [Role::Owner, Role::Strategist], true) && $features->enabled($organization, CampaignFeature::Templates),
            ],
            'formOptions' => CampaignController::formOptions($organization->id),
        ];

        if ($view === 'timeline') {
            $today = CampaignClock::today($organization);

            return Inertia::render('campaigns/index', [
                ...$props,
                'today' => $today->toDateString(),
                'timeline' => $list->timeline($organization, $filters)->map(fn (Campaign $campaign) => [
                    ...CampaignPresenter::chip($campaign),
                    'status' => $campaign->status->value,
                    'start_date' => $campaign->start_date?->toDateString(),
                    'end_date' => $campaign->end_date?->toDateString(),
                    'client' => ['id' => $campaign->client_id, 'name' => $campaign->client?->name],
                ])->values(),
                'campaigns' => null,
            ]);
        }

        $result = $list($organization, $filters, max(1, $request->integer('page', 1)));

        return Inertia::render('campaigns/index', [
            ...$props,
            'today' => $result['today']->toDateString(),
            'timeline' => null,
            'campaigns' => $result['page']->withQueryString()->through(fn (Campaign $campaign) => CampaignPresenter::forStaff($campaign, $user, $result['today'], [
                'posts_total' => (int) $campaign->getAttribute('posts_total'),
                'posts_published' => (int) $campaign->getAttribute('posts_published'),
                'deliverables_total' => (int) $campaign->getAttribute('deliverables_total'),
                'health' => $result['health'][$campaign->id] ?? null,
                'readiness' => $result['readiness'][$campaign->id] ?? null,
            ], withBilling: false)),
        ]);
    }
}
