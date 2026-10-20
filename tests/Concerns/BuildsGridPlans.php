<?php

namespace Tests\Concerns;

use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\GridPlanStatus;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Phase 18 test fixtures: an organization with a client, a connected Instagram account, staff in
 * every role and a portal contact, plus helpers to build plans and tiles with real media.
 */
trait BuildsGridPlans
{
    protected Organization $org;

    protected Client $client;

    protected SocialAccount $instagram;

    /**
     * @var array<string, User>
     */
    protected array $staff = [];

    protected User $reviewer;

    protected function setUpGridOrg(string $timezone = 'Europe/London'): void
    {
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['approval_email' => 'client@example.com']);
        $this->instagram = SocialAccount::factory()->for($this->client)->connected()->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'handle' => '@studio',
            'timezone' => $timezone,
        ]);

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
    protected function makePlan(array $attributes = []): GridPlan
    {
        return GridPlan::factory()->create([
            'org_id' => $this->org->id,
            'client_id' => $this->client->id,
            'social_account_id' => $this->instagram->id,
            'created_by' => $this->owner()->id,
            ...$attributes,
        ]);
    }

    /**
     * An image asset of this client with a known size.
     */
    protected function image(int $width = 1080, int $height = 1440, ?Client $client = null): Asset
    {
        $client ??= $this->client;

        return Asset::factory()->for($client)->create([
            'org_id' => $client->org_id,
            'type' => AssetType::Image,
            'width' => $width,
            'height' => $height,
        ]);
    }

    protected function video(int $width = 1080, int $height = 1920): Asset
    {
        return Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'type' => AssetType::Video,
            'width' => $width,
            'height' => $height,
            'duration_ms' => 15000,
            'mime_type' => 'video/mp4',
        ]);
    }

    /**
     * Add a tile at the next position with the given media (images by default).
     *
     * @param  list<Asset>|null  $assets
     * @param  array<string, mixed>  $attributes
     */
    protected function addTile(GridPlan $plan, GridTileKind $kind = GridTileKind::Image, ?array $assets = null, array $attributes = []): GridTile
    {
        $position = (int) $plan->tiles()->max('position') + 1;

        /** @var GridTile $tile */
        $tile = $plan->tiles()->create([
            'position' => $position,
            'kind' => $kind,
            'format' => $kind->defaultFormat(),
            ...$attributes,
        ]);

        $assets ??= match ($kind) {
            GridTileKind::Placeholder => [],
            GridTileKind::Carousel => [$this->image(), $this->image()],
            GridTileKind::Reel => [$this->video()],
            GridTileKind::Image => [$this->image()],
        };

        foreach ($assets as $order => $asset) {
            $tile->assets()->attach($asset->id, ['sort_order' => $order]);
        }

        return $tile;
    }

    /**
     * A plan with `$count` single-image tiles at 3:4.
     */
    protected function planWithTiles(int $count = 3, GridPlanStatus $status = GridPlanStatus::Draft): GridPlan
    {
        $plan = $this->makePlan(['status' => $status]);

        for ($i = 0; $i < $count; $i++) {
            $this->addTile($plan);
        }

        return $plan->refresh();
    }

    protected function disconnect(SocialAccount $account): void
    {
        $account->update(['connection_status' => ConnectionStatus::TokenExpired]);
    }

    protected function setFormat(GridTile $tile, MediaRatio $format): void
    {
        $tile->update(['format' => $format]);
    }
}
