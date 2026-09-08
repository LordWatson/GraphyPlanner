<?php

namespace App\Http\Requests;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateSocialAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('socialAccount'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', new Enum(Platform::class)],
            'handle' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'language' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'max:2'],
            'default_location' => ['nullable', 'string', 'max:255'],
            'posting_windows' => ['nullable', 'array'],
            'posting_windows.*.day' => ['required', 'string', 'max:20'],
            'posting_windows.*.start' => ['required', 'string', 'max:5'],
            'posting_windows.*.end' => ['required', 'string', 'max:5'],
            'persona_override' => ['nullable', 'string'],
            'connection_status' => ['nullable', new Enum(ConnectionStatus::class)],
        ];
    }
}
