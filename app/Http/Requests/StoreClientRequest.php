<?php

namespace App\Http\Requests;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
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
            'owner_user_id' => ['nullable', Rule::exists('users', 'id')->where('org_id', $this->user()->org_id)],
            'start_date' => ['nullable', 'date'],
            'retainer_amount' => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => ['nullable', new Enum(BillingCycle::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'notes_internal' => ['nullable', 'string'],
            'approval_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
