<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendInvoiceReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('remind', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
