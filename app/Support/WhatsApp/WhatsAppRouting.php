<?php

namespace App\Support\WhatsApp;

use App\Models\ClientContact;
use App\Models\User;
use App\Support\Notifications\NotificationPreferences;

/**
 * Step W-0.5 / W-2 — the one check a notification's `via()` makes before adding `'whatsapp'`.
 *
 * The two audiences consent in different ways, and this is the only place that difference lives:
 *
 * - a **member** opts in per notification category and must have verified their number;
 * - a **client contact** has one consent flag, recorded with its source, and a number.
 *
 * `WhatsAppChannel` still has the final say — the suppression list outranks both — so this is the
 * cheap "should we even try" check, not the authority.
 */
final class WhatsAppRouting
{
    public static function wants(object $notifiable, string $notificationClass): bool
    {
        if ($notifiable instanceof User) {
            return NotificationPreferences::wantsWhatsApp($notifiable, $notificationClass);
        }

        if ($notifiable instanceof ClientContact) {
            return $notifiable->routeNotificationForWhatsApp() !== null;
        }

        // An on-demand `Notification::route()` has no organization to send as, and so no credentials.
        return false;
    }

    /**
     * The channels a notification should use: its own, plus WhatsApp when this recipient wants it.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public static function with(array $channels, object $notifiable, string $notificationClass): array
    {
        return self::wants($notifiable, $notificationClass)
            ? [...$channels, 'whatsapp']
            : $channels;
    }
}
