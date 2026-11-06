<?php

namespace App\Mail;

use App\Enums\ProductType;
use App\Models\Order;
use App\Support\MoneyFormatter;
use App\Support\Queues;
use App\Support\Store\IcsFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step CG-4.3 / CG-4.5 — the buyer's receipt, and the delivery with it: the download link, the
 * booking details (plus an `.ics` and a manage link), the thank-you for a tip, or the creator's
 * answer to a paid question. One mailable for all four because it is one email with one layout —
 * the blade branches on the product type.
 *
 * It is sent from the creator's brand, not from Alima: the buyer bought from them.
 */
class OrderReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $downloadUrl = null,
        public ?string $manageBookingUrl = null,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        $order = $this->order->loadMissing(['product.client', 'booking']);
        $product = $order->product;
        $brand = $product->client->name ?? config('app.name');
        $timezone = $product->client->organization->default_timezone ?? config('app.timezone', 'UTC');

        $subject = match ($product->type) {
            ProductType::Download => sprintf('Your download: %s', $product->title),
            ProductType::Booking => sprintf('Your booking with %s', $brand),
            ProductType::Tip => sprintf('Thank you from %s', $brand),
            ProductType::Qa => $order->answer !== null
                ? sprintf('%s answered your question', $brand)
                : sprintf('%s has your question', $brand),
        };

        $mail = $this->subject($subject)->view('emails.order-receipt', [
            'order' => $order,
            'product' => $product,
            'brand' => $brand,
            'amount' => MoneyFormatter::format($order->amount_minor / 100, $order->currency),
            'downloadUrl' => $this->downloadUrl,
            'manageBookingUrl' => $this->manageBookingUrl,
            'bookingLabel' => $order->booking?->starts_at->clone()->setTimezone($timezone)->isoFormat('dddd D MMMM, HH:mm'),
            'timezone' => $timezone,
        ]);

        if ($order->booking !== null) {
            $mail->attach(Attachment::fromData(
                fn (): string => IcsFile::forBooking($order->booking, sprintf('%s — %s', $product->title, $brand)),
                'booking.ics',
            )->withMime('text/calendar'));
        }

        return $mail;
    }
}
