<?php

namespace App\Http\Requests\Business;

use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\AudienceBroadcast;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step GTM-2.3 — the one-off email the owner is about to send. Authorised by the brand-update
 * ability, like every other write in the business shell: a Designer can draft content but cannot
 * email the brand's list.
 */
class SendBroadcastRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    public function authorize(): bool
    {
        $brand = $this->brand();

        return $brand !== null && $this->user()->can('update', $brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'segment' => ['required', Rule::in([AudienceBroadcast::SEGMENT_ALL, AudienceBroadcast::SEGMENT_PRODUCT])],
            'product_id' => ['nullable', 'integer', 'required_if:segment,'.AudienceBroadcast::SEGMENT_PRODUCT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required_if' => __('Pick the product whose buyers should get this.'),
        ];
    }
}
