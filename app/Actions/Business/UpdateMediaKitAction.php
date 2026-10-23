<?php

namespace App\Actions\Business;

use App\Models\Client;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.4 — save the editable part of a brand's media kit (currently just the bio). Find-or-create
 * so the first save creates the row; never touches the share state.
 */
class UpdateMediaKitAction
{
    public function __construct(private readonly BuildMediaKitAction $build) {}

    public function __invoke(Client $brand, ?string $bio): MediaKit
    {
        $kit = $this->build->kitFor($brand);
        $kit->update(['bio' => $bio !== null && trim($bio) !== '' ? trim($bio) : null]);

        Log::info('Media kit updated', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

        return $kit;
    }
}
