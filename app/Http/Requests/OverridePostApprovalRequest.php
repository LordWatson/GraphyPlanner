<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step I-3.8 / M-2.9 — an Owner approving a `client_required` post without the client must say
 * why; the reason is kept on the approval and the activity log.
 */
class OverridePostApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('overrideApproval', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Say why the client is being skipped — it goes on the activity log.'),
            'reason.min' => __('Give a little more detail — it goes on the activity log.'),
        ];
    }
}
