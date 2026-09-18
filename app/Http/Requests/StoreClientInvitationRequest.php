<?php

namespace App\Http\Requests;

use App\Models\ClientInvitation;
use Illuminate\Foundation\Http\FormRequest;
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
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * Reject a duplicate pending invitation to the same email/client.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $client = $this->route('client');

            if (! $client || blank($this->input('email'))) {
                return;
            }

            $hasPending = $client->invitations()
                ->where('email', $this->input('email'))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($hasPending) {
                $validator->errors()->add('email', 'There is already a pending invitation for this email.');
            }
        });
    }
}
