<?php

namespace App\Actions\Store;

use App\Contracts\PaymentProvider;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Mail\OrderReceiptMail;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step CG-4.5 — the creator answers a paid question, which is the moment the money is actually
 * taken: the card was only *authorised* at checkout, so capture happens here and nowhere else.
 *
 * Refused if the window has closed (`authorised_until`), because the authorisation will have been
 * released by then (`ExpireStaleOrdersAction`) and a capture would fail at the provider. The answer
 * is saved and emailed in the same transaction as the capture, so the buyer is never charged for an
 * answer we then failed to record.
 */
class AnswerQuestionAction
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly TransitionOrderStatusAction $transition,
    ) {}

    public function __invoke(Order $order, string $answer, ?User $actor = null): Order
    {
        $answer = trim($answer);

        if ($order->product->type !== ProductType::Qa) {
            throw new DomainException('Only a paid question can be answered.');
        }

        if ($order->status !== OrderStatus::Paid) {
            throw new DomainException(sprintf('A %s question cannot be answered.', $order->status->value));
        }

        if ($order->authorised_until !== null && $order->authorised_until->isPast()) {
            throw new DomainException('The window for answering this has passed, so the payment was released.');
        }

        if ($answer === '') {
            throw new DomainException('Write an answer first.');
        }

        $account = PaymentAccount::query()->withoutOrgScope()->where('org_id', $order->org_id)->firstOrFail();

        // Capture first: if the provider refuses, nothing here claims the question was answered.
        $this->provider->capture($account, $order);

        return DB::transaction(function () use ($order, $answer, $actor): Order {
            $order->update(['answer' => $answer]);
            $order = ($this->transition)($order, OrderStatus::Fulfilled, ['by_user_id' => $actor?->id, 'captured' => true]);

            Mail::to($order->buyer_email)->send(new OrderReceiptMail($order));

            Log::info('Paid question answered and captured', [
                'order_id' => $order->id,
                'org_id' => $order->org_id,
                'user_id' => $actor?->id,
            ]);

            return $order;
        });
    }
}
