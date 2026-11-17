<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Native shell — the FCM device token `@capacitor/push-notifications` hands the app on
 * registration, and which platform it came from.
 */
class StorePushDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ];
    }
}
