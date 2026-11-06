<?php

namespace App\Exceptions\Store;

use RuntimeException;
use Throwable;

/**
 * Step CG-4.1 — the payment provider refused or failed. Wrapping the vendor exception keeps Stripe's
 * own wording (and anything it quotes back from the request) out of the UI: callers show
 * {@see self::friendlyMessage()} and the real cause goes to the log with the order/account id only.
 */
class PaymentProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function from(string $operation, Throwable $previous): self
    {
        return new self(
            sprintf('Payment provider failed during %s: %s', $operation, $previous->getMessage()),
            $operation,
            $previous,
        );
    }

    public function friendlyMessage(): string
    {
        return __('The payment provider couldn’t complete that just now. Try again in a minute.');
    }
}
