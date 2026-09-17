<?php

namespace App\Support\Options;

use DateTimeZone;

/**
 * All IANA timezone identifiers offered as dropdown options wherever a SocialAccount's
 * timezone (validated via Laravel's `timezone:all` rule) is captured in the UI.
 */
final class TimezoneOptions
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $identifier) => ['value' => $identifier, 'label' => $identifier],
            DateTimeZone::listIdentifiers(),
        );
    }
}
