<?php

namespace App\Actions\Organizations;

use App\Contracts\AssetStorage;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-3.1 — store or remove an organization's white-label logo. It goes on the configured
 * `AssetStorage` disk (private by default) and is handed out as a signed URL, like every other
 * uploaded file: an agency's artwork is public-facing, but that is no reason to leave it at a
 * permanent guessable address.
 *
 * Replacing a logo deletes the old file **after** the new columns commit, so a failure can never
 * leave the organization pointing at bytes that are gone.
 */
class UpdateBrandLogoAction
{
    public function __construct(private readonly AssetStorage $storage) {}

    public function __invoke(Organization $organization, UploadedFile $file): Organization
    {
        $previous = [$organization->brand_logo_disk, $organization->brand_logo_path];

        $stored = $this->storage->store($file, "branding/{$organization->id}");

        DB::transaction(function () use ($organization, $stored) {
            $organization->forceFill([
                'brand_logo_disk' => $stored['disk'],
                'brand_logo_path' => $stored['path'],
            ])->save();
        });

        $this->forget($organization, $previous);

        Log::info('Organization brand logo updated', [
            'org_id' => $organization->id,
            'bytes' => $stored['size'],
        ]);

        return $organization;
    }

    public function remove(Organization $organization): Organization
    {
        $previous = [$organization->brand_logo_disk, $organization->brand_logo_path];

        DB::transaction(function () use ($organization) {
            $organization->forceFill(['brand_logo_disk' => null, 'brand_logo_path' => null])->save();
        });

        $this->forget($organization, $previous);

        Log::info('Organization brand logo removed', ['org_id' => $organization->id]);

        return $organization;
    }

    /**
     * @param  array{0: string|null, 1: string|null}  $previous
     */
    private function forget(Organization $organization, array $previous): void
    {
        [$disk, $path] = $previous;

        if ($disk === null || $path === null) {
            return;
        }

        // A file we can't delete is a tidying problem, never a failed save.
        try {
            $this->storage->delete($disk, $path);
        } catch (\Throwable $e) {
            Log::warning('Could not delete a replaced brand logo', [
                'org_id' => $organization->id,
                'exception' => $e::class,
            ]);
        }
    }
}
