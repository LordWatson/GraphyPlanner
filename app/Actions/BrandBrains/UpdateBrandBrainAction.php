<?php

namespace App\Actions\BrandBrains;

use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateBrandBrainAction
{
    /**
     * Create or update the brand brain for the given client.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data): BrandBrain
    {
        return DB::transaction(function () use ($client, $data) {
            $brandBrain = BrandBrain::updateOrCreate(
                ['client_id' => $client->id],
                $data,
            );

            Log::info('Brand brain updated', [
                'client_id' => $client->id,
                'brand_brain_id' => $brandBrain->id,
            ]);

            return $brandBrain;
        });
    }
}
