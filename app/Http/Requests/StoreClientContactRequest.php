<?php

namespace App\Http\Requests;

use App\Enums\ClientContactRole;
use App\Models\Client;
use App\Models\ClientContact;
use App\Rules\SendableWhatsAppNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step M-6.1 — add a contact to the route's client.
 */
class StoreClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ClientContact::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('client_contacts', 'email')
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at'),
            ],
            // Step W-1.2: consent needs somewhere to send it. A number without a country code
            // cannot be dialled, so it is refused here rather than failing silently at send time.
            'receives_whatsapp' => ['nullable', 'boolean'],
            'phone' => ['nullable', 'string', 'max:50', Rule::when(
                $this->boolean('receives_whatsapp'),
                ['required', new SendableWhatsAppNumber],
            )],
            'role' => ['required', new Enum(ClientContactRole::class)],
            'receives_review_emails' => ['nullable', 'boolean'],
            'receives_invoices' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{name: ?string, email: string, phone: ?string, role: ClientContactRole, receives_review_emails: ?bool, receives_invoices: ?bool, receives_whatsapp: bool}
     */
    public function payload(): array
    {
        return [
            'name' => $this->input('name'),
            'email' => (string) $this->input('email'),
            'phone' => $this->input('phone'),
            'role' => ClientContactRole::from((string) $this->input('role')),
            'receives_review_emails' => $this->has('receives_review_emails') ? $this->boolean('receives_review_emails') : null,
            'receives_invoices' => $this->has('receives_invoices') ? $this->boolean('receives_invoices') : null,
            'receives_whatsapp' => $this->boolean('receives_whatsapp'),
        ];
    }
}
