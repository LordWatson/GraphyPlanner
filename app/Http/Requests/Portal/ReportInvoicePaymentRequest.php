<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-4.2 — the optional detail a client can attach when reporting a payment from the portal.
 * Ownership (the invoice belongs to the logged-in contact's client) is enforced in the controller,
 * which never trusts a value from the request.
 */
class ReportInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->client_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
