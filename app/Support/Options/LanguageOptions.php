<?php

namespace App\Support\Options;

/**
 * Curated list of ISO 639-1 language codes offered as dropdown options wherever a client's
 * default_language (or similar free-text language field) is captured in the UI.
 */
final class LanguageOptions
{
    /**
     * @var array<string, string>
     */
    private const LANGUAGES = [
        'en' => 'English',
        'nl' => 'Dutch',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'it' => 'Italian',
        'pl' => 'Polish',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'no' => 'Norwegian',
        'fi' => 'Finnish',
        'tr' => 'Turkish',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ru' => 'Russian',
    ];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $label) => ['value' => $code, 'label' => $label],
            array_keys(self::LANGUAGES),
            array_values(self::LANGUAGES),
        );
    }
}
