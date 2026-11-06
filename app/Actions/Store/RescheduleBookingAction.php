<?php

namespace App\Actions\Store;

use App\Enums\OrderStatus;
use App\Exceptions\Store\CheckoutUnavailableException;
use App\Mail\OrderReceiptMail;
use App\Models\Order;
use App\Services\Store\BookingSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step CG-4.5 — the buyer moving or cancelling their own booking from the signed link in their
 * receipt. No account, no email to the creator asking: self-service, which is what every booking
 * tool in the segment does.
 *
 * Moving re-checks live availability (the new slot must be genuinely free and far enough ahead) and
 * re-sends the receipt with a fresh `.ics`, so the buyer's calendar follows. Cancelling deletes the
 * booking row — that is what frees the slot — and leaves the order itself alone: whether to refund
 * is the creator's call ({@see RefundOrderAction}), not something a link decides.
 */
class RescheduleBookingAction
{
    public function __construct(
        private readonly BookingSlotService $slots,
        private readonly FulfilOrderAction $fulfil,
    ) {}

    public function move(Order $order, string $slot): Order
    {
        $booking = $order->booking;

        if ($booking === null || ! $order->status->isPaid()) {
            throw new CheckoutUnavailableException(__('There is no live booking to move.'));
        }

        $startsAt = CarbonImmutable::parse($slot)->utc();

        if (! $this->slots->isBookable($booking->product, $startsAt)) {
            throw new CheckoutUnavailableException(__('That time is not available — pick another.'));
        }

        $minutes = $this->slots->availability($booking->product)['slot_minutes'];

        DB::transaction(function () use ($order, $booking, $startsAt, $minutes): void {
            $from = $booking->starts_at->toIso8601String();

            $booking->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($minutes)]);
            $order->recordEvent('booking.moved', ['from' => $from, 'to' => $startsAt->toIso8601String()]);
        });

        Log::info('Booking moved by buyer', ['order_id' => $order->id, 'org_id' => $order->org_id]);

        $order->refresh()->loadMissing('booking');

        Mail::to($order->buyer_email)->send(new OrderReceiptMail(
            $order,
            manageBookingUrl: $this->fulfil->manageBookingUrl($order),
        ));

        return $order;
    }

    public function cancel(Order $order): Order
    {
        $booking = $order->booking;

        if ($booking === null) {
            throw new CheckoutUnavailableException(__('There is no live booking to cancel.'));
        }

        DB::transaction(function () use ($order, $booking): void {
            $order->recordEvent('booking.cancelled', ['was' => $booking->starts_at->toIso8601String()]);
            $booking->delete();
        });

        Log::info('Booking cancelled by buyer', [
            'order_id' => $order->id,
            'org_id' => $order->org_id,
            'order_status' => $order->status->value,
        ]);

        return $order->refresh();
    }

    /**
     * A cancelled-then-refunded order is the creator's business; this only reports whether the buyer
     * may still act on the booking at all.
     */
    public function buyerMayManage(Order $order): bool
    {
        return $order->booking !== null
            && $order->status !== OrderStatus::Refunded
            && $order->booking->starts_at->isFuture();
    }
}
