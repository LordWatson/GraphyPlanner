<?php

namespace App\Services\Notifications;

use App\Contracts\ChatNotifier;
use App\Models\Organization;

/**
 * Step M-6.7 — the MVP's `ChatNotifier`: a no-op. Team-chat delivery is a planned WhatsApp
 * integration; until it lands, this keeps every caller working (and testable) with nothing sent.
 */
class NullChatNotifier implements ChatNotifier
{
    public function notifyOrganization(Organization $organization, string $title, string $body, ?string $url = null): void
    {
        // Intentionally does nothing — no chat channel is wired up yet.
    }

    public function isConfiguredFor(Organization $organization): bool
    {
        return false;
    }
}
