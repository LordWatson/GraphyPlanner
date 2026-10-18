<?php

namespace App\Http\Middleware;

use App\Contracts\CampaignFeatures;
use App\Enums\CampaignFeature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Campaigns enhancement C-9.4 — `campaign.feature:<feature>` on a route answers 403 with a clear
 * message when the organization doesn't have that campaign feature.
 */
class EnsureCampaignFeature
{
    public function __construct(private readonly CampaignFeatures $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $gate = CampaignFeature::from($feature);
        $user = $request->user();

        if (! $this->features->enabled($user?->organization, $gate)) {
            Log::info('Campaign feature blocked', [
                'feature' => $gate->value,
                'org_id' => $user?->org_id,
                'user_id' => $user?->id,
            ]);

            abort(403, $gate->offMessage());
        }

        return $next($request);
    }
}
