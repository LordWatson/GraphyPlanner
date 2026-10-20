<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step I-1.5 purge, hardened by Step I-16.2 / M-1.2: permanently deleting an archived client
 * can't be undone, so the Owner re-enters their password.
 */
class PurgeClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return $this->user()?->can('forceDelete', $client) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
