<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Actions\Campaigns\UpdateCampaignAction;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;

class CampaignController extends Controller
{
    /**
     * Create a new campaign for the given client.
     */
    public function store(StoreCampaignRequest $request, Client $client, CreateCampaignAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Update an existing campaign.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign, UpdateCampaignAction $action): RedirectResponse
    {
        $action($campaign, $request->validated());

        return to_route('clients.show', $campaign->client_id);
    }

    /**
     * Delete a campaign.
     */
    public function destroy(Campaign $campaign): RedirectResponse
    {
        $this->authorize('delete', $campaign);

        $clientId = $campaign->client_id;

        $campaign->delete();

        return to_route('clients.show', $clientId);
    }
}
