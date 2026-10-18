<?php

namespace Tests\Feature\Campaigns\Concerns;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Shared fixtures for the campaigns enhancement tests: an organization with a client, staff in
 * every role, and a portal contact for the client.
 */
trait BuildsCampaigns
{
    protected Organization $org;

    protected Client $client;

    /**
     * @var array<string, User>
     */
    protected array $staff = [];

    protected User $reviewer;

    protected function setUpCampaignOrg(string $timezone = 'UTC'): void
    {
        $this->org = Organization::factory()->create(['default_timezone' => $timezone]);
        $this->client = Client::factory()->for($this->org, 'organization')->create();

        foreach ([Role::Owner, Role::Strategist, Role::Designer, Role::Viewer] as $role) {
            $this->staff[$role->value] = User::factory()->for($this->org, 'organization')->role($role)->create();
        }

        $this->reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $this->client->id,
        ]);
    }

    protected function owner(): User
    {
        return $this->staff[Role::Owner->value];
    }

    protected function strategist(): User
    {
        return $this->staff[Role::Strategist->value];
    }

    protected function designer(): User
    {
        return $this->staff[Role::Designer->value];
    }

    protected function viewer(): User
    {
        return $this->staff[Role::Viewer->value];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCampaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            ...$attributes,
        ]);
    }

    /**
     * Give the client a connected account on each platform, so the campaign can plan for it.
     */
    protected function connect(Platform ...$platforms): void
    {
        foreach ($platforms as $platform) {
            SocialAccount::factory()->for($this->client)->create([
                'org_id' => $this->org->id,
                'platform' => $platform,
                'connection_status' => ConnectionStatus::Connected,
            ]);
        }
    }

    protected function foreignOwner(): User
    {
        $org = Organization::factory()->create();

        return User::factory()->for($org, 'organization')->role(Role::Owner)->create();
    }
}
