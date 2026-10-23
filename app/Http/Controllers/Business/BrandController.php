<?php

namespace App\Http\Controllers\Business;

use App\Actions\BrandBrains\SyncBrandReferenceAssetsAction;
use App\Actions\BrandBrains\UpdateBrandBrainAction;
use App\Actions\Clients\UpdateClientAction;
use App\Enums\ReferenceImageRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBrandProfileRequest;
use App\Http\Requests\UpdateBrandBrainRequest;
use App\Http\Requests\UpdateBrandReferencesRequest;
use App\Models\BrandBrain;
use App\Policies\BrandBrainPolicy;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's "Brand" page: the brand's profile (its voice, audience, offer
 * and pillars — the same `BrandBrain` the agency edits), plus the business-only basics (name, what
 * you do, location) and the posting goal (B-4.5). The brand is implicit, resolved from
 * {@see CurrentWorkspace}, and every save calls the same Actions the agency controllers use.
 */
class BrandController extends Controller
{
    public function edit(Request $request, CurrentWorkspace $workspace, BrandBrainPolicy $policy): Response
    {
        $brand = $workspace->brand();

        if (! $policy->viewForClient($request->user(), $brand)) {
            abort(403);
        }

        $brandBrain = $brand->brandBrain ?? new BrandBrain(['client_id' => $brand->id]);

        return Inertia::render('business/brand', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'one_liner' => $brand->industry,
                'location' => $brand->location,
                'website' => $brand->website,
                'posting_goal' => $brand->postingGoal()->toArray(),
            ],
            'brandBrain' => [
                'voice' => $brandBrain->voice,
                'audience' => $brandBrain->audience,
                'offer' => $brandBrain->offer,
                'visual' => $brandBrain->visual,
                'music_policy' => $brandBrain->music_policy,
                'hashtag_policy' => $brandBrain->hashtag_policy,
                'content_pillars' => $brandBrain->content_pillars,
            ],
            'references' => $brandBrain->exists
                ? $brandBrain->referenceAssets()->with('asset')->get()
                    ->filter(fn ($reference) => $reference->asset !== null)
                    ->map(fn ($reference) => [
                        'asset_id' => $reference->asset_id,
                        'role' => $reference->role->value,
                        'url' => $reference->asset?->url,
                        'original_filename' => $reference->asset?->original_filename,
                    ])->values()
                : [],
            'referenceRoles' => array_map(fn (ReferenceImageRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], ReferenceImageRole::brandRoles()),
            'maxReferences' => (int) config('ai.images.max_references', 8),
            'can' => [
                'update' => $policy->update($request->user(), $brand),
            ],
        ]);
    }

    /**
     * The brand's basics and posting goal.
     */
    public function updateProfile(UpdateBrandProfileRequest $request, CurrentWorkspace $workspace, UpdateClientAction $action): RedirectResponse
    {
        $action($workspace->brand(), $request->brandData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand saved.')]);

        return to_route('business.brand.edit');
    }

    /**
     * The brand profile (voice/audience/offer/pillars) — the shared Brand Brain.
     */
    public function update(UpdateBrandBrainRequest $request, CurrentWorkspace $workspace, UpdateBrandBrainAction $action): RedirectResponse
    {
        $action($workspace->brand(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand profile saved.')]);

        return to_route('business.brand.edit');
    }

    /**
     * The brand's visual references for the AI Image Studio (Step 16.4).
     */
    public function updateReferences(UpdateBrandReferencesRequest $request, CurrentWorkspace $workspace, SyncBrandReferenceAssetsAction $action): RedirectResponse
    {
        $action($workspace->brand(), $request->user(), $request->validated('references'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visual references saved.')]);

        return to_route('business.brand.edit');
    }
}
