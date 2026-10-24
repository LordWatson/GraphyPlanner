<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-9.3 — an optional steer for the AI reply draft ("offer a discount code", "keep it short").
 * AI access (per-org toggle + role) is enforced in the controller via `AiAccess`.
 */
class DraftInboxReplyRequest extends FormRequest
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
            'instruction' => ['nullable', 'string', 'max:500'],
        ];
    }
}
