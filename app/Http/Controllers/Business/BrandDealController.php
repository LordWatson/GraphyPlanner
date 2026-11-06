<?php

namespace App\Http\Controllers\Business;

use App\Actions\Deals\GetBrandDealsAction;
use App\Actions\Deals\SaveBrandDealAction;
use App\Contracts\Entitlements;
use App\Enums\DealStatus;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveBrandDealRequest;
use App\Models\BrandDeal;
use App\Models\MediaKit;
use App\Models\Post;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-5.2 — the brand-deals tracker (`/deals`): who the creator is working with, what they owe,
 * and what has actually gone out (counted from the deal's published posts, never stored).
 *
 * Thin: one {@see GetBrandDealsAction} read, one {@see SaveBrandDealAction} write. The tracker is a
 * plan gate ({@see Feature::BrandDeals}); the page still renders without it and explains what
 * upgrading would unlock, exactly as `/bio` and `/store` do. Writes need the brand-update ability.
 */
class BrandDealController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GetBrandDealsAction $deals): Response
    {
        $brand = $workspace->brand();
        $this->authorize('viewAny', [Post::class, $brand]);

        $organization = $brand->organization;
        $kit = MediaKit::query()->where('client_id', $brand->id)->first();

        return Inertia::render('business/deals', [
            'deals' => $deals($brand),
            'statuses' => array_map(
                fn (DealStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                DealStatus::selectable(),
            ),
            'platforms' => array_map(
                fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()],
                Platform::cases(),
            ),
            'currency' => $organization->default_currency ?? 'GBP',
            // Where "show on my media kit" actually shows up, so the toggle isn't a promise the
            // owner can't see the result of.
            'mediaKit' => [
                'is_public' => $kit?->isPublic() ?? false,
                'accepts_enquiries' => $kit?->acceptsEnquiries() ?? false,
            ],
            'entitled' => $organization !== null && app(Entitlements::class)->allows($organization, Feature::BrandDeals),
            'canManage' => $request->user()?->can('update', $brand) ?? false,
        ]);
    }

    public function store(SaveBrandDealRequest $request, CurrentWorkspace $workspace, SaveBrandDealAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $action($brand, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal saved.')]);

        return back();
    }

    public function update(SaveBrandDealRequest $request, BrandDeal $deal, CurrentWorkspace $workspace, SaveBrandDealAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($deal->client_id === $brand->id, 404);

        $action($brand, $request->validated(), $deal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal saved.')]);

        return back();
    }

    public function destroy(BrandDeal $deal, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($deal->client_id === $brand->id, 404);

        // Posts keep their own partner name and disclosure; they just stop counting towards a deal.
        $deal->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deal removed.')]);

        return back();
    }
}
