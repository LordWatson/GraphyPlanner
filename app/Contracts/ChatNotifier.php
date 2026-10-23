<?php

namespace App\Contracts;

use App\Models\Organization;

/**
 * Step M-6.7 (I-12.1) — a swappable team-chat notifier. The MVP binds a no-op `NullChatNotifier`;
 * a WhatsApp implementation (the chosen chat integration) drops in behind this contract later
 * without touching callers. Deliberately not Slack.
 */
interface ChatNotifier
{
    /**
     * Post a short message to the organization's configured chat channel. A no-op when the org has
     * no channel configured (or the active implementation is the null one). Never throws into the
     * caller — delivery is best-effort.
     */
    public function notifyOrganization(Organization $organization, string $title, string $body, ?string $url = null): void;

    /**
     * Whether a real chat channel is wired up for this org (false for the null implementation).
     */
    public function isConfiguredFor(Organization $organization): bool;
}
