<?php

namespace App\Http\Controllers\Business;

use App\Actions\Store\AnswerQuestionAction;
use App\Actions\Store\DraftLaunchCampaignAction;
use App\Actions\Store\GetStoreOverviewAction;
use App\Actions\Store\RefundOrderAction;
use App\Actions\Store\StartConnectOnboardingAction;
use App\Actions\Store\SyncPaymentAccountAction;
use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Exceptions\Store\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Models\BioPage;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Post;
use App\Models\Product;
use App\Services\Workspace\CurrentWorkspace;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Step CG-4.6 — the creator's shop: products, orders, earnings and the connected-account state, plus
 * the few actions that act on an order (refund, answer a paid question) and the launch campaign.
 *
 * Thin throughout: the page is one {@see GetStoreOverviewAction} read, and every mutation is one
 * Action call. Selling is a plan gate ({@see Feature::Store}); the page still renders without it and
 * explains what upgrading would unlock, exactly as `/bio` does.
 *
 * Mutations need the brand-update ability (Owner/Manager) — money is not a Designer's or Viewer's to
 * move.
 */
class StoreController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GetStoreOverviewAction $overview): Response
    {
        $brand = $workspace->brand();
        $this->authorize('viewAny', [Post::class, $brand]);

        $organization = $brand->organization;

        return Inertia::render('business/store', [
            ...$overview($brand),
            'coupons' => Coupon::query()
                ->where('org_id', $brand->org_id)
                ->orderBy('code')
                ->get()
                ->map(fn (Coupon $coupon) => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'product_id' => $coupon->product_id,
                    'percent_off' => $coupon->percent_off,
                    'amount_off_minor' => $coupon->amount_off_minor,
                    'expires_at' => $coupon->expires_at?->toIso8601String(),
                    'max_redemptions' => $coupon->max_redemptions,
                    'redemptions' => $coupon->redemptions,
                ])
                ->values(),
            'types' => array_map(
                fn (ProductType $type) => ['value' => $type->value, 'label' => $type->label()],
                ProductType::cases(),
            ),
            'statuses' => array_map(
                fn (ProductStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                ProductStatus::cases(),
            ),
            'currencies' => array_values((array) config('payments.currencies')),
            'hasBioPage' => BioPage::query()->where('client_id', $brand->id)->whereNotNull('published_at')->exists(),
            'entitled' => $organization !== null && app(Entitlements::class)->allows($organization, Feature::Store),
            'canManage' => $request->user()?->can('update', $brand) ?? false,
        ]);
    }

    /**
     * Send the creator to Stripe's hosted onboarding (or back into it to finish their details).
     */
    public function setup(Request $request, CurrentWorkspace $workspace, StartConnectOnboardingAction $action): HttpResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_if($brand->organization === null, 404);

        try {
            $url = $action($brand->organization, route('business.store.return'), route('business.store.index'));
        } catch (PaymentProviderException $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'message' => $e->friendlyMessage()]);
        }

        // A full-page visit: Stripe's hosted flow can't live inside an Inertia XHR.
        return Inertia::location($url);
    }

    /**
     * Stripe redirects back here when onboarding is done. Reconcile immediately rather than wait for
     * the `account.updated` webhook, so the page is right on first load.
     */
    public function returned(Request $request, CurrentWorkspace $workspace, SyncPaymentAccountAction $sync): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        $account = PaymentAccount::query()->where('org_id', $brand->org_id)->first();

        if ($account !== null) {
            try {
                $sync($account);
            } catch (PaymentProviderException $e) {
                report($e);
            }
        }

        return to_route('business.store.index')->with('toast', [
            'type' => $account?->canSell() ? 'success' : 'info',
            'message' => $account?->canSell()
                ? __('You can take payments now.')
                : __('Stripe still needs a few details before you can take payments.'),
        ]);
    }

    public function refund(Request $request, Order $order, CurrentWorkspace $workspace, RefundOrderAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($order->org_id === $brand->org_id, 404);

        try {
            $action($order, $request->user());
        } catch (DomainException|PaymentProviderException $e) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => $e instanceof PaymentProviderException ? $e->friendlyMessage() : $e->getMessage(),
            ]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Refunded.')]);
    }

    public function answer(Request $request, Order $order, CurrentWorkspace $workspace, AnswerQuestionAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($order->org_id === $brand->org_id, 404);

        $validated = $request->validate(['answer' => ['required', 'string', 'max:5000']]);

        try {
            $action($order, $validated['answer'], $request->user());
        } catch (DomainException|PaymentProviderException $e) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => $e instanceof PaymentProviderException ? $e->friendlyMessage() : $e->getMessage(),
            ]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Answer sent — and your payment taken.')]);
    }

    /**
     * Step CG-4.7 — draft a launch campaign from a product. Drafts only: the owner schedules.
     */
    public function launch(Request $request, Product $product, CurrentWorkspace $workspace, DraftLaunchCampaignAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($product->client_id === $brand->id, 404);

        $campaign = $action($product, $request->user());

        return to_route('business.collections.index')->with('toast', [
            'type' => 'success',
            'message' => __('":name" is drafted — review the posts and schedule them when you are happy.', ['name' => $campaign->name]),
        ]);
    }
}
