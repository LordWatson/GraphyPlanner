<?php

namespace App\Http\Requests;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Http\Requests\Concerns\ValidatesPlatformSettings;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformCapabilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreSocialAccountRequest extends FormRequest
{
    use ValidatesPlatformSettings;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [SocialAccount::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Step M-2.6: only platforms switched on for publishing (rollout flags).
            'platform' => ['required', new Enum(Platform::class), Rule::in(array_map(
                fn (Platform $platform) => $platform->value,
                app(PlatformCapabilities::class)->enabledPlatforms(),
            ))],
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
            ...$this->platformSettingsRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validatePlatformSettings($validator)];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedAccount(): array
    {
        return self::cleanPlatformSettings($this->validated());
    }
}
