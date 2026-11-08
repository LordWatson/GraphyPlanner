<?php

namespace App\Services\Notifications;

use App\Contracts\ChatNotifier;
use App\Models\Organization;

/**
 * Step M-6.7 — the off switch for team chat (`CHAT_NOTIFIER=null`): keeps every caller working,
 * and testable, with nothing sent. `SlackChatNotifier` is what is bound by default.
 */
class NullChatNotifier implements ChatNotifier
{
    public function notifyOrganization(Organization $organization, string $title, string $body, ?string $url = null): void
    {
        // Intentionally does nothing — team chat is switched off.
    }

    public function isConfiguredFor(Organization $organization): bool
    {
        return false;
    }
}
