<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\RescheduleBookingAction;
use App\Exceptions\Store\CheckoutUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Store\BookingSlotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-4.5 — the buyer managing their own booking from the signed link in their receipt: see it,
 * move it, cancel it. No account needed; the signature *is* the authorisation, which is why the move
 * and cancel URLs handed to the page are signed too rather than relying on the page having been
 * reached legitimately.
 */
class BookingController extends Controller
{
    public function show(Order $order, BookingSlotService $slots, RescheduleBookingAction $action): Response
    {
        $order->loadMissing(['product.client', 'booking']);
        $booking = $order->booking;

        abort_if($booking === null, 404);

        $timezone = $slots->timezone($booking->product);

        return Inertia::render('public/booking', [
            'brandName' => $order->product->client?->name,
            'productTitle' => $order->product->title,
            'timezone' => $timezone,
            'booking' => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'label' => $booking->starts_at->clone()->setTimezone($timezone)->isoFormat('dddd D MMMM, HH:mm'),
                'can_manage' => $action->buyerMayManage($order),
            ],
            'slots' => $action->buyerMayManage($order) ? $slots->slots($booking->product) : [],
            // Signed, so each form carries its own authorisation.
            'moveUrl' => URL::signedRoute('store.booking.move', ['order' => $order->id]),
            'cancelUrl' => URL::signedRoute('store.booking.cancel', ['order' => $order->id]),
        ]);
    }

    public function move(Request $request, Order $order, RescheduleBookingAction $action): RedirectResponse
    {
        $validated = $request->validate(['slot' => ['required', 'date']]);

        try {
            $action->move($order, $validated['slot']);
        } catch (CheckoutUnavailableException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Moved — your new time is on its way by email.')]);
    }

    public function cancel(Order $order, RescheduleBookingAction $action): RedirectResponse
    {
        try {
            $action->cancel($order);
        } catch (CheckoutUnavailableException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Cancelled. Anything owed back is up to the seller — drop them a line.'),
        ]);
    }
}
