<?php

namespace App\Support\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * Step M-6.7 (I-12.1) — resolves a user's notification preferences, applying sensible defaults.
 * Each category has two channels: `in_app` (the bell) and `digest` (the daily email). Defaults are
 * everything on, except the chatty `comments` digest and the `other` digest (kept quiet by default).
 * `Other` is never muteable in-app, so a user can't accidentally hide everything uncategorised.
 */
final class NotificationPreferences
{
    public const CHANNELS = ['in_app', 'digest'];

    /**
     * @return array<string, bool>
     */
    private static function defaultsFor(NotificationCategory $category): array
    {
        return [
            'in_app' => true,
            'digest' => ! in_array($category, [NotificationCategory::Comments, NotificationCategory::Other], true),
        ];
    }

    /**
     * Whether the user wants `$channel` for `$category` (falling back to the default). `Other` always
     * shows in-app.
     */
    public static function wants(User $user, NotificationCategory $category, string $channel): bool
    {
        if ($channel === 'in_app' && $category === NotificationCategory::Other) {
            return true;
        }

        $stored = $user->notification_preferences[$category->value][$channel] ?? null;

        return is_bool($stored) ? $stored : (self::defaultsFor($category)[$channel] ?? true);
    }

    public static function wantsInApp(User $user, string $notificationClass): bool
    {
        return self::wants($user, NotificationCategory::forNotificationClass($notificationClass), 'in_app');
    }

    /**
     * The full preference matrix for the settings UI, merging stored values over the defaults.
     *
     * @return list<array{category: string, label: string, description: string, in_app: bool, digest: bool, in_app_locked: bool}>
     */
    public static function matrix(User $user): array
    {
        return array_map(fn (NotificationCategory $category) => [
            'category' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'in_app' => self::wants($user, $category, 'in_app'),
            'digest' => self::wants($user, $category, 'digest'),
            // `Other` can't be muted in-app.
            'in_app_locked' => $category === NotificationCategory::Other,
        ], NotificationCategory::cases());
    }

    /**
     * Normalise a submitted preferences payload to `{category: {in_app, digest}}` booleans, ignoring
     * unknown categories/channels. `Other`'s in-app stays on.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, bool>>
     */
    public static function sanitize(array $input): array
    {
        $clean = [];

        foreach (NotificationCategory::cases() as $category) {
            $row = $input[$category->value] ?? [];
            $clean[$category->value] = [
                'in_app' => $category === NotificationCategory::Other ? true : (bool) ($row['in_app'] ?? false),
                'digest' => (bool) ($row['digest'] ?? false),
            ];
        }

        return $clean;
    }
}
