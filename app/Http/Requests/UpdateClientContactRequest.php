<?php

namespace App\Http\Requests;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step M-6.1 — edit the route's contact.
 */
class UpdateClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ClientContact $contact */
        $contact = $this->route('contact');

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('client_contacts', 'email')
                    ->where('client_id', $contact->client_id)
                    ->whereNull('deleted_at')
                    ->ignore($contact->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', new Enum(ClientContactRole::class)],
            'receives_review_emails' => ['required', 'boolean'],
            'receives_invoices' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{name: ?string, email: string, phone: ?string, role: ClientContactRole, receives_review_emails: bool, receives_invoices: bool}
     */
    public function payload(): array
    {
        return [
            'name' => $this->input('name'),
            'email' => (string) $this->input('email'),
            'phone' => $this->input('phone'),
            'role' => ClientContactRole::from((string) $this->input('role')),
            'receives_review_emails' => $this->boolean('receives_review_emails'),
            'receives_invoices' => $this->boolean('receives_invoices'),
        ];
    }
}
