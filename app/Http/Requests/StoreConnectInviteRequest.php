<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step M-2.8 — a client self-connect link: who to email (optional), which account slots or
 * platforms it covers (optional; default all of the client's accounts), and how long it lasts.
 */
class StoreConnectInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createConnectInvite', [SocialAccount::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $client = $this->route('client');
        $clientId = $client instanceof Client ? $client->id : null;

        return [
            'email' => ['nullable', 'email', 'max:255'],
            'social_account_ids' => ['nullable', 'array'],
            // Step I-1.9: only this client's accounts — never another client's or org's.
            'social_account_ids.*' => ['integer', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => [new Enum(Platform::class)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
