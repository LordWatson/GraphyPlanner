<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\CreateCheckoutAction;
use App\Actions\Store\FulfilOrderAction;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Exceptions\Store\CheckoutUnavailableException;
use App\Exceptions\Store\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StartCheckoutRequest;
use App\Mail\OrderReceiptMail;
use App\Models\BioPage;
use App\Models\Order;
use App\Models\Product;
use App\Services\Store\BookingSlotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Step CG-4.2 / CG-4.3 / CG-4.5 — the buyer's side of the store, on the creator's bio handle:
 * the product page, starting a checkout, and the thank-you page.
 *
 * Unauthenticated by design, like the bio page and the review portal: a buyer is an email address,
 * not an account. Nothing here renders anything but public data — a product that isn't live, or one
 * whose page isn't published, 404s — and the thank-you page shows the *purchase*, never the buyer's
 * details, because its only secret is an order id.
 */
class CheckoutController extends Controller
{
    public function show(string $handle, int $product, BookingSlotService $slots): Response
    {
        [$page, $found] = $this->resolve($handle, $product);

        return Inertia::render('public/product', [
            'theme' => $page->theme->value,
            'handle' => $page->handle,
            'brandName' => $page->client?->name,
            'product' => [
                'id' => $found->id,
                'type' => $found->type->value,
                'type_label' => $found->type->label(),
                'title' => $found->title,
                'description' => $found->description,
                'price_minor' => $found->price_minor,
                'currency' => $found->currency,
                'cover_url' => $found->coverAsset?->thumbnailUrl(),
                'min_minor' => $found->type->buyerSetsAmount()
                    ? (int) $found->setting('min_minor', $found->price_minor)
                    : null,
                'takes_message' => $found->type->takesBuyerMessage(),
                'holds_payment' => $found->type->isAuthorisedThenCaptured(),
            ],
            // Only a booking needs them, and they're a live read: the list goes stale in minutes.
            'slots' => $found->type === ProductType::Booking ? $slots->slots($found) : [],
        ]);
    }

    public function checkout(StartCheckoutRequest $request, string $handle, int $product, CreateCheckoutAction $action): HttpResponse
    {
        [$page, $found] = $this->resolve($handle, $product);

        try {
            $checkout = $action($found, $request->validated(), $page->handle);
        } catch (CheckoutUnavailableException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        } catch (PaymentProviderException $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'message' => $e->friendlyMessage()]);
        }

        // A full-page visit: the card form is the provider's own hosted page, never ours.
        return Inertia::location($checkout['url']);
    }

    /**
     * The thank-you page. Reached straight from checkout, so it may load before the webhook has
     * landed — it says "we're confirming" rather than pretending, and the page polls nothing.
     */
    public function thanks(string $handle, Order $order): Response
    {
        $page = $this->resolvePage($handle);
        $order->loadMissing(['product.client', 'booking']);

        abort_unless($order->product->client_id === $page->client_id, 404);

        return Inertia::render('public/order-thanks', [
            'theme' => $page->theme->value,
            'handle' => $page->handle,
            'brandName' => $page->client?->name,
            'order' => [
                'id' => $order->id,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'product_title' => $order->product->title,
                'product_type' => $order->product->type->value,
                'booked_for' => $order->booking?->starts_at->toIso8601String(),
                'is_paid' => $order->status->isPaid(),
            ],
        ]);
    }

    /**
     * Step CG-4.3 — "email me that link again", keyed on the order *and* the buyer's own email. A
     * wrong email gets the same answer as a right one, so the page can't be used to test addresses.
     */
    public function resend(Request $request, string $handle, Order $order, FulfilOrderAction $fulfil): RedirectResponse
    {
        $page = $this->resolvePage($handle);
        $order->loadMissing('product');

        abort_unless($order->product->client_id === $page->client_id, 404);

        $validated = $request->validate(['buyer_email' => ['required', 'email:filter', 'max:255']]);
        $matches = strtolower(trim($validated['buyer_email'])) === $order->buyer_email;

        if ($matches && $order->canDownload() && $order->product->type === ProductType::Download) {
            Mail::to($order->buyer_email)->send(new OrderReceiptMail($order, downloadUrl: $fulfil->downloadUrl($order)));
            $order->recordEvent('download.link_resent');
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('If that is the address on this order, the link is on its way.'),
        ]);
    }

    /**
     * @return array{0: BioPage, 1: Product}
     */
    private function resolve(string $handle, int $productId): array
    {
        $page = $this->resolvePage($handle);

        $product = Product::query()
            ->withoutOrgScope()
            ->where('client_id', $page->client_id)
            ->where('status', ProductStatus::Live)
            ->with('coverAsset')
            ->find($productId);

        abort_if($product === null, 404);

        return [$page, $product];
    }

    private function resolvePage(string $handle): BioPage
    {
        $page = BioPage::query()->where('handle', strtolower($handle))->first();

        abort_if($page === null || ! $page->isPublished() || $page->client === null, 404);

        return $page;
    }
}
