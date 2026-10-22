<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationSettingsUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->organization !== null
            && $this->user()->can('update', $this->user()->organization);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', 'size:3'],
            'billing_details' => ['nullable', 'string', 'max:2000'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            // Step M-4.1: the agency's VAT/TRN number and the label to print it under.
            'tax_registration_number' => ['nullable', 'string', 'max:60'],
            'tax_registration_label' => ['nullable', 'string', 'max:30'],
            'invoice_payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'upload_post_key' => ['nullable', 'string', 'max:2048'],
            'upload_post_webhook_secret' => ['nullable', 'string', 'max:2048'],
            'xai_key' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
