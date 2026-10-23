<?php

namespace App\Actions\Business;

use App\Models\Client;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.4 — turn the media kit's public link on (opt-in). Mints a fresh random slug and stamps
 * `shared_at`; calling it again rotates the token, so any previously-shared link stops resolving —
 * the owner's "generate a new link" and the implicit revoke-old-links are the same action.
 */
class ShareMediaKitAction
{
    public function __construct(private readonly BuildMediaKitAction $build) {}

    public function __invoke(Client $brand): MediaKit
    {
        $kit = $this->build->kitFor($brand);
        $kit->update([
            'share_token' => BuildMediaKitAction::newToken(),
            'shared_at' => now(),
        ]);

        Log::info('Media kit share link enabled', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

        return $kit;
    }
}
