<?php

namespace App\Actions\Benchmarks;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\TrackedProfile;
use App\Models\User;
use App\Services\Analytics\Benchmarks\CompositeCompetitorProfileSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step GTM-4.1 — track one public profile against a brand.
 *
 * Both plan checks live here: `Feature::CompetitorBenchmarks` is whether the org has the feature at
 * all, and `Feature::TrackedProfiles` is how many profiles it may keep — counted **per
 * organization**, not per brand, because every profile is a nightly read against somebody else's
 * rate limit whichever brand it hangs off.
 *
 * A platform with no legitimate public read is refused outright rather than accepted and left to
 * produce nothing but gaps.
 */
class AddTrackedProfileAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly SyncTrackedProfileAction $sync,
    ) {}

    public function __invoke(Client $brand, Platform $platform, string $handle, ?string $label = null, ?User $by = null): TrackedProfile
    {
        $organization = $brand->organization;

        if ($organization !== null) {
            $this->entitlements->ensure($organization, Feature::CompetitorBenchmarks);
        }

        if (! CompositeCompetitorProfileSource::supports($platform)) {
            throw ValidationException::withMessages([
                'platform' => __(':platform has no public figures we are allowed to read, so it cannot be benchmarked.', [
                    'platform' => $platform->label(),
                ]),
            ]);
        }

        $handle = TrackedProfile::normaliseHandle($handle);

        if ($handle === '') {
            throw ValidationException::withMessages(['handle' => __('Enter the profile handle.')]);
        }

        $existing = TrackedProfile::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['handle' => __('You are already tracking that profile.')]);
        }

        if ($organization !== null) {
            $this->entitlements->ensure(
                $organization,
                Feature::TrackedProfiles,
                TrackedProfile::query()->withoutOrgScope()->where('org_id', $organization->id)->count(),
            );
        }

        $profile = DB::transaction(fn (): TrackedProfile => TrackedProfile::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'platform' => $platform,
            'handle' => $handle,
            'label' => trim((string) $label) ?: null,
            'added_by' => $by?->id,
        ]));

        Log::info('Tracked profile added', [
            'org_id' => $profile->org_id,
            'client_id' => $profile->client_id,
            'tracked_profile_id' => $profile->id,
            'platform' => $platform->value,
        ]);

        // Outside the transaction: one read now, so a handle we will never get a figure for says
        // so immediately instead of looking fine until tomorrow. It never throws.
        ($this->sync)($profile);

        return $profile->refresh();
    }
}
