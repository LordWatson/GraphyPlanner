<?php

namespace App\Http\Requests\Store;

use App\Actions\Store\CreateCheckoutAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step CG-4.2 — what a buyer submits to start a checkout. Deliberately tiny: an email we can send
 * the receipt to, optionally a name, and the few fields a type needs (a tip amount, a question, a
 * slot). No address, no account, no card data — the card is only ever entered on the provider's own
 * hosted page.
 *
 * The amount is accepted but never trusted: {@see CreateCheckoutAction} charges
 * the product's price for everything except a tip, and floors a tip at the product's minimum.
 */
class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'buyer_email' => ['required', 'email:filter', 'max:255'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
            'amount_minor' => ['nullable', 'integer', 'min:100', 'max:5000000'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:2000'],
            'slot' => ['nullable', 'date'],
            // Step GTM-2.1: the buyer's own marketing opt-in. Its absence is a no, and buying
            // without ticking it is not consent to anything but the receipt.
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }
}
