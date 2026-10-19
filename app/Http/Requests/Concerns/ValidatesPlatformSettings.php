<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Platform;
use Illuminate\Validation\Validator;

/**
 * Step I-3.4 / M-2.6 — `platform_settings` on a social account may only carry the settings the
 * capability registry lists for that platform (`config/platforms.php` → `settings`).
 */
trait ValidatesPlatformSettings
{
    /**
     * @return array<string, mixed>
     */
    protected function platformSettingsRules(): array
    {
        return [
            'platform_settings' => ['nullable', 'array'],
            'platform_settings.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function validatePlatformSettings(Validator $validator): void
    {
        $settings = $this->input('platform_settings');
        $platform = Platform::tryFrom((string) $this->input('platform'));

        if (! is_array($settings) || $platform === null) {
            return;
        }

        $allowed = array_keys($platform->spec()->settings);

        foreach (array_keys($settings) as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add("platform_settings.{$key}", __(':platform doesn’t use that setting.', ['platform' => $platform->label()]));
            }
        }
    }

    /**
     * Blank settings are dropped, so the stored map only holds what's set.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected static function cleanPlatformSettings(array $validated): array
    {
        if (array_key_exists('platform_settings', $validated)) {
            $settings = array_filter((array) ($validated['platform_settings'] ?? []), fn ($value) => filled($value));
            $validated['platform_settings'] = $settings === [] ? null : $settings;
        }

        return $validated;
    }
}
