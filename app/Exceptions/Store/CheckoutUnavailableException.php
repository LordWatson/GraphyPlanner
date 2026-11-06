<?php

namespace App\Exceptions\Store;

use RuntimeException;

/**
 * Step CG-4.2 — the buyer can't check out, for a reason that's theirs to see: the product isn't on
 * sale, the creator hasn't finished connecting their payment account, the coupon has expired, the
 * booking slot has gone. Rendered as a plain message on the public page, never a 500.
 */
class CheckoutUnavailableException extends RuntimeException {}
