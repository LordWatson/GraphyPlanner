<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:2'],
            'status' => ['required', new Enum(ClientStatus::class)],
            // Step I-3.8 / M-2.9: how this client's new posts get approved.
            'default_approval_mode' => ['nullable', new Enum(ApprovalMode::class)],
            'owner_user_id' => ['nullable', Rule::exists('users', 'id')->where('org_id', $this->user()->org_id)],
            'start_date' => ['nullable', 'date'],
            'retainer_amount' => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => ['nullable', new Enum(BillingCycle::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'notes_internal' => ['nullable', 'string'],
            'approval_email' => ['nullable', 'email', 'max:255'],
            // Step M-3.4: the agreed posting cadence (client health flags a month well below it).
            'target_posts_per_week' => ['nullable', 'integer', 'min:1', 'max:50'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            // Step M-4.1 / M-4.3: tax registration, per-client billing currency, default tax rate
            // and the recurring-retainer opt-in.
            'tax_registration_number' => ['nullable', 'string', 'max:60'],
            'billing_currency' => ['nullable', 'string', Rule::in(CurrencyOptions::codes())],
            'default_tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('org_id', $this->user()->org_id)],
            'auto_invoice_retainer' => ['nullable', 'boolean'],
        ];
    }
}
