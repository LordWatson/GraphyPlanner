<?php

namespace App\Contracts;

use App\Models\Organization;

/**
 * Step M-6.7 (I-12.1) — a swappable team-chat notifier. `SlackChatNotifier` is the bound default
 * (`NullChatNotifier` when `CHAT_NOTIFIER=null`); Teams or anything else drops in behind this
 * contract without touching callers.
 *
 * Not the same seam as WhatsApp: this posts one message to a team's channel, where
 * `App\Contracts\WhatsAppClient` messages a named person who opted in. The WhatsApp digest in
 * Step W-2.6 may fulfil this one too.
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
