<?php

namespace App\Http\Requests\Settings;

use App\Enums\Role;
use App\Support\Plans\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-2.4 — validates a Stripe Checkout request: a known public, non-agency plan, a supported
 * currency and interval. Owner-only (billing).
 */
class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role === Role::Owner && $user->org_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $checkoutPlans = array_values(array_map(
            fn ($plan) => $plan->key,
            array_filter(app(PlanCatalog::class)->public(), fn ($plan) => $plan->segment() !== 'agency' && $plan->isPaid()),
        ));

        return [
            'plan' => ['required', 'string', Rule::in($checkoutPlans)],
            'currency' => ['required', 'string', Rule::in(['gbp', 'usd'])],
            'interval' => ['required', 'string', Rule::in(['monthly', 'annual'])],
        ];
    }
}
