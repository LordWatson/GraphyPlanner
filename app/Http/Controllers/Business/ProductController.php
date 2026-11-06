<?php

namespace App\Http\Controllers\Business;

use App\Actions\Store\SaveProductAction;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveCouponRequest;
use App\Http\Requests\Business\SaveProductRequest;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Step CG-4.2 / CG-4.4 — the products and discount codes behind `/store`. Thin: validate, call
 * {@see SaveProductAction}, redirect. Every route needs the brand-update ability, and every record
 * is re-checked against the workspace brand so one brand can never edit another's product.
 *
 * "Delete" archives: an order's history has to keep pointing at what was bought.
 */
class ProductController extends Controller
{
    public function store(SaveProductRequest $request, CurrentWorkspace $workspace, SaveProductAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $action($brand, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return back();
    }

    public function update(SaveProductRequest $request, Product $product, CurrentWorkspace $workspace, SaveProductAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($product->client_id === $brand->id, 404);

        $action($brand, $request->validated(), $product);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return back();
    }

    public function destroy(Product $product, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($product->client_id === $brand->id, 404);

        $product->update(['status' => ProductStatus::Archived]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Archived — it is off sale and your orders are untouched.')]);

        return back();
    }

    public function storeCoupon(SaveCouponRequest $request, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $validated = $request->validated();
        $productId = $validated['product_id'] ?? null;

        Coupon::updateOrCreate(
            ['org_id' => $brand->org_id, 'code' => strtoupper($validated['code'])],
            [
                // A code can only be scoped to this brand's own product.
                'product_id' => $productId === null ? null : Product::query()
                    ->where('client_id', $brand->id)
                    ->whereKey((int) $productId)
                    ->value('id'),
                'percent_off' => $validated['percent_off'] ?? null,
                'amount_off_minor' => $validated['amount_off_minor'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'max_redemptions' => $validated['max_redemptions'] ?? null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Code saved.')]);

        return back();
    }

    public function destroyCoupon(Coupon $coupon, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($coupon->org_id === $brand->org_id, 404);

        $coupon->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Code removed.')]);

        return back();
    }
}
