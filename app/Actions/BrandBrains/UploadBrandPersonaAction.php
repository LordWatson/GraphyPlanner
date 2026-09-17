<?php

namespace App\Actions\BrandBrains;

use App\Contracts\AssetStorage;
use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UploadBrandPersonaAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Store (or replace) the Brand Persona PDF for the given client's brand brain.
     */
    public function __invoke(Client $client, UploadedFile $file): BrandBrain
    {
        return DB::transaction(function () use ($client, $file) {
            $brandBrain = BrandBrain::firstOrCreate(['client_id' => $client->id]);

            if ($brandBrain->hasPersona()) {
                $this->storage->delete($brandBrain->persona_disk, $brandBrain->persona_path);
            }

            $directory = "brand-personas/{$client->org_id}/{$client->id}";
            $stored = $this->storage->store($file, $directory);

            $brandBrain->update([
                'persona_disk' => $stored['disk'],
                'persona_path' => $stored['path'],
                'persona_url' => $stored['url'],
                'persona_original_filename' => $file->getClientOriginalName(),
                'persona_mime_type' => $stored['mime_type'],
                'persona_size' => $stored['size'],
            ]);

            Log::info('Brand persona uploaded', [
                'client_id' => $client->id,
                'brand_brain_id' => $brandBrain->id,
            ]);

            return $brandBrain;
        });
    }
}
