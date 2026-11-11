<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\ClientInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [ClientInvitation::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        // Portal access is not a second list: an invite only ever goes to one of this client's
        // contacts, so removing the contact is enough to remove the access.
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::exists('client_contacts', 'email')
                    ->where('client_id', $client->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Reject a duplicate pending invitation to the same email/client.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ?Client $client */
            $client = $this->route('client');

            if (! $client || blank($this->input('email'))) {
                return;
            }

            $hasPending = $client->invitations()
                ->where('email', $this->input('email'))
                ->pending()
                ->exists();

            if ($hasPending) {
                $validator->errors()->add('email', 'There is already a pending invitation for this email.');
            }
        });
    }
}
