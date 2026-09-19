<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Actions\Campaigns\UpdateCampaignAction;
use App\Enums\CampaignStatus;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * Display the specified campaign, including the posts attached to it.
     */
    public function show(Request $request, Campaign $campaign): Response
    {
        $this->authorize('view', $campaign);

        $user = $request->user();

        return Inertia::render('campaigns/show', [
            'campaign' => $this->transform($campaign),
            'client' => [
                'id' => $campaign->client_id,
                'name' => $campaign->client->name,
            ],
            'posts' => $campaign->posts()
                ->with('targets.socialAccount')
                ->latest()
                ->get()
                ->map(fn (Post $post) => $this->transformPost($post)),
            'can' => [
                'update' => $user->can('update', $campaign),
                'delete' => $user->can('delete', $campaign),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified campaign.
     */
    public function edit(Campaign $campaign): Response
    {
        $this->authorize('update', $campaign);

        return Inertia::render('campaigns/edit', [
            'campaign' => $this->transform($campaign),
            'client' => [
                'id' => $campaign->client_id,
                'name' => $campaign->client->name,
            ],
            'statuses' => array_map(
                fn (CampaignStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                CampaignStatus::cases(),
            ),
        ]);
    }

    /**
     * Update an existing campaign.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign, UpdateCampaignAction $action): RedirectResponse
    {
        $action($campaign, $request->validated());

        return to_route('campaigns.show', $campaign);
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

    /**
     * Transform a campaign model into an array for the show/edit pages.
     *
     * @return array<string, mixed>
     */
    private function transform(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'goal' => $campaign->goal,
            'notes' => $campaign->notes,
        ];
    }

    /**
     * Transform a post model into an array for the campaign show page.
     *
     * @return array<string, mixed>
     */
    private function transformPost(Post $post): array
    {
        return [
            'id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'master_caption' => $post->master_caption,
            'targets' => $post->targets->map(fn ($target) => [
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
                'scheduled_local_date' => $target->scheduled_local_date?->toDateString(),
            ])->values(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
