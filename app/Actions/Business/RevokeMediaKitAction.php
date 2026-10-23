<?php

namespace App\Actions\Business;

use App\Models\Client;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.4 — turn the media kit's public link off. Clearing the token makes every shared link
 * stop resolving immediately; the kit (and its bio) are kept so it can be re-shared later.
 */
class RevokeMediaKitAction
{
    public function __construct(private readonly BuildMediaKitAction $build) {}

    public function __invoke(Client $brand): MediaKit
    {
        $kit = $this->build->kitFor($brand);
        $kit->update(['share_token' => null, 'shared_at' => null]);

        Log::info('Media kit share link revoked', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

        return $kit;
    }
}
