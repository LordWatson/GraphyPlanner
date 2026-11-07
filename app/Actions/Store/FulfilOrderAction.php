<?php

namespace App\Actions\Store;

use App\Actions\Audience\CaptureAudienceContactAction;
use App\Enums\AudienceSource;
use App\Enums\AudienceTrigger;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Jobs\SendAudienceTriggerJob;
use App\Mail\OrderReceiptMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Step CG-4.3 / CG-4.5 — deliver what a paid order bought, and email the buyer their receipt.
 *
 * What "delivered" means depends on the type:
 *
 *  - **download** — a *signed, expiring* link to our own download route (never the file's storage
 *    URL, which stays private), honouring the product's expiry window and use limit;
 *  - **booking** — the slot claimed at checkout, confirmed, with an `.ics` and a signed
 *    manage link so the buyer can move or cancel it themselves;
 *  - **tip** — nothing to deliver, so it is done the moment it is paid;
 *  - **qa** — *not* fulfilled here. The money is only authorised; the creator answering is what
 *    captures it ({@see AnswerQuestionAction}).
 */
class FulfilOrderAction
{
    public function __construct(
        private readonly TransitionOrderStatusAction $transition,
        private readonly CaptureAudienceContactAction $capture,
    ) {}

    public function __invoke(Order $order): Order
    {
        $product = $order->product;
        $downloadUrl = null;

        if ($product->type === ProductType::Download) {
            $downloadUrl = $this->downloadUrl($order);
            $order->recordEvent('download.link_issued', ['expires_in_hours' => $product->downloadExpiryHours()]);
        }

        Mail::to($order->buyer_email)->send(new OrderReceiptMail(
            $order,
            downloadUrl: $downloadUrl,
            manageBookingUrl: $order->booking === null ? null : $this->manageBookingUrl($order),
        ));

        Log::info('Order receipt sent', [
            'order_id' => $order->id,
            'org_id' => $order->org_id,
            'type' => $product->type->value,
        ]);

        // Step GTM-2.2: the follow-up, a day later so it isn't a second email in the same minute.
        // The job re-checks consent and the unsubscribe at delivery time, not now.
        SendAudienceTriggerJob::dispatch(
            ($this->capture)($product->client, $order->buyer_email, $order->buyer_name, AudienceSource::Order)->id,
            AudienceTrigger::Purchase,
            (string) $order->id,
        )->delay(now()->addDay());

        // A paid question stays `paid` (authorised) until the creator answers.
        if ($product->type->isAuthorisedThenCaptured()) {
            return $order;
        }

        return ($this->transition)($order, OrderStatus::Fulfilled);
    }

    /**
     * A fresh signed download link. Time-limited by the product; the use limit and refund check live
     * in the route itself, so an unexpired link still stops working the moment either applies.
     */
    public function downloadUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'store.download',
            now()->addHours($order->product->downloadExpiryHours()),
            ['order' => $order->id],
        );
    }

    /**
     * The buyer's own booking page (move or cancel). Signed and long-lived — it is the only thing
     * standing between a stranger and someone else's booking, so it carries no guessable id alone.
     */
    public function manageBookingUrl(Order $order): string
    {
        return URL::signedRoute('store.booking.show', ['order' => $order->id]);
    }
}
