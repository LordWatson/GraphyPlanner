<?php

namespace App\Actions\Store;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Step CG-4.6 — everything `/store` shows: the products, the orders, what the creator has earned,
 * and where their payment account stands. One read so the page needs no logic of its own.
 *
 * Earnings are summed from *our* orders, not from Stripe's balance: it is the number the creator can
 * reconcile against this page, and it needs no API call to render. Payouts live at Stripe, so the
 * page links out for those rather than pretending to know their schedule.
 */
class GetStoreOverviewAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $brand): array
    {
        $account = PaymentAccount::query()->withoutOrgScope()->where('org_id', $brand->org_id)->first();

        return [
            'account' => $account === null ? null : [
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'requirements_due' => $account->requirements_due ?? [],
                'country' => $account->country,
            ],
            'products' => $this->products($brand),
            'orders' => $this->orders($brand),
            'earnings' => $this->earnings($brand),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(Client $brand): array
    {
        return array_values(Product::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->withCount(['orders as sales_count' => fn ($query) => $query->whereIn('status', [OrderStatus::Paid, OrderStatus::Fulfilled])])
            ->orderByRaw("status = 'archived'")
            ->orderBy('title')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'type' => $product->type->value,
                'type_label' => $product->type->label(),
                'title' => $product->title,
                'description' => $product->description,
                'price_minor' => $product->price_minor,
                'currency' => $product->currency,
                'status' => $product->status->value,
                'status_label' => $product->status->label(),
                'cover_asset_id' => $product->cover_asset_id,
                'download_asset_id' => $product->download_asset_id,
                'settings' => $product->settings ?? [],
                'sales_count' => (int) $product->sales_count,
                'public_url' => $product->publicUrl(),
            ])
            ->all());
    }

    /**
     * The 50 most recent orders. A paid question carries its question and answer so the creator can
     * reply from the same table that shows the money.
     *
     * @return list<array<string, mixed>>
     */
    private function orders(Client $brand): array
    {
        return array_values(Order::query()
            ->withoutOrgScope()
            ->whereIn('product_id', Product::query()->withoutOrgScope()->where('client_id', $brand->id)->select('id'))
            ->with(['product', 'booking'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'product_title' => $order->product->title,
                'product_type' => $order->product->type->value,
                'buyer_email' => $order->buyer_email,
                'buyer_name' => $order->buyer_name,
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'message' => $order->message,
                'answer' => $order->answer,
                'answer_due_at' => $order->authorised_until?->toIso8601String(),
                'booked_for' => $order->booking?->starts_at->toIso8601String(),
                'created_at' => $order->created_at?->toIso8601String(),
                'can_refund' => $order->status->canTransitionTo(OrderStatus::Refunded),
                'can_answer' => $order->status === OrderStatus::Paid
                    && $order->product->type->isAuthorisedThenCaptured()
                    && ($order->authorised_until === null || $order->authorised_until->isFuture()),
            ])
            ->all());
    }

    /**
     * Gross taken and refunded, per currency (a creator may price in more than one).
     *
     * @return list<array{currency: string, paid_minor: int, refunded_minor: int, orders: int}>
     */
    private function earnings(Client $brand): array
    {
        $rows = DB::table('orders')
            ->whereIn('product_id', DB::table('products')->where('client_id', $brand->id)->select('id'))
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Fulfilled->value,
                OrderStatus::Refunded->value,
            ])
            ->groupBy('currency')
            ->select([
                'currency',
                DB::raw("SUM(CASE WHEN status = 'refunded' THEN 0 ELSE amount_minor END) as paid_minor"),
                DB::raw("SUM(CASE WHEN status = 'refunded' THEN amount_minor ELSE 0 END) as refunded_minor"),
                DB::raw('COUNT(*) as order_count'),
            ])
            ->get();

        return array_values($rows->map(fn ($row) => [
            'currency' => (string) $row->currency,
            'paid_minor' => (int) $row->paid_minor,
            'refunded_minor' => (int) $row->refunded_minor,
            'orders' => (int) $row->order_count,
        ])->all());
    }
}
