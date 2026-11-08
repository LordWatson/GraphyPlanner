<?php

namespace App\Support\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * Step M-6.7 (I-12.1) — resolves a user's notification preferences, applying sensible defaults.
 * Each category has three channels: `in_app` (the bell), `digest` (the daily email) and, from Step
 * W-0.5, `whatsapp`. Defaults are everything on, except the chatty `comments` digest and the
 * `other` digest (kept quiet by default). `Other` is never muteable in-app, so a user can't
 * accidentally hide everything uncategorised.
 *
 * `whatsapp` is **off for every category**, for everybody, and is never forced on: it is somebody's
 * personal phone, and Meta's own policy requires an explicit opt-in. `wantsWhatsApp()` also
 * requires a verified number, so turning the preference on without one still sends nothing.
 */
final class NotificationPreferences
{
    public const CHANNELS = ['in_app', 'digest', 'whatsapp'];

    /**
     * @return array<string, bool>
     */
    private static function defaultsFor(NotificationCategory $category): array
    {
        return [
            'in_app' => true,
            'digest' => ! in_array($category, [NotificationCategory::Comments, NotificationCategory::Other], true),
            'whatsapp' => false,
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
     * Step W-0.5 — the single check a notification's `via()` makes before adding `'whatsapp'`:
     * the user asked for this category on WhatsApp **and** has a verified number to send to.
     *
     * Both halves live here so no notification class can accidentally implement only one of them.
     */
    public static function wantsWhatsApp(User $user, string $notificationClass): bool
    {
        return $user->routeNotificationForWhatsApp() !== null
            && self::wants($user, NotificationCategory::forNotificationClass($notificationClass), 'whatsapp');
    }

    /**
     * The full preference matrix for the settings UI, merging stored values over the defaults.
     *
     * @return list<array{category: string, label: string, description: string, in_app: bool, digest: bool, whatsapp: bool, in_app_locked: bool}>
     */
    public static function matrix(User $user): array
    {
        return array_map(fn (NotificationCategory $category) => [
            'category' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'in_app' => self::wants($user, $category, 'in_app'),
            'digest' => self::wants($user, $category, 'digest'),
            // No `whatsapp_locked` companion: unlike `Other`'s in-app, WhatsApp is never locked on.
            'whatsapp' => self::wants($user, $category, 'whatsapp'),
            // `Other` can't be muted in-app.
            'in_app_locked' => $category === NotificationCategory::Other,
        ], NotificationCategory::cases());
    }

    /**
     * Normalise a submitted preferences payload to `{category: {in_app, digest, whatsapp}}`
     * booleans, ignoring unknown categories/channels. `Other`'s in-app stays on.
     *
     * A channel the payload does not mention keeps whatever `$existing` holds rather than being
     * switched off. That matters from Step W-0.5 on: the settings form submits `in_app`/`digest`
     * only, and without this a routine save would silently wipe every WhatsApp opt-in the user
     * had made somewhere else.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing  the user's stored preferences
     * @return array<string, array<string, bool>>
     */
    public static function sanitize(array $input, array $existing = []): array
    {
        $clean = [];

        foreach (NotificationCategory::cases() as $category) {
            $row = is_array($input[$category->value] ?? null) ? $input[$category->value] : [];
            $stored = is_array($existing[$category->value] ?? null) ? $existing[$category->value] : [];
            $defaults = self::defaultsFor($category);

            foreach (self::CHANNELS as $channel) {
                $value = $row[$channel] ?? $stored[$channel] ?? $defaults[$channel] ?? false;
                $clean[$category->value][$channel] = (bool) $value;
            }

            // `Other` can't be muted in-app.
            if ($category === NotificationCategory::Other) {
                $clean[$category->value]['in_app'] = true;
            }
        }

        return $clean;
    }
}
