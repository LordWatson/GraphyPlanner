<?php

namespace App\Enums;

/**
 * Step B-5.1 — how an organization's publishing vendor credentials are sourced.
 *
 * - `byo` ("bring your own"): the org pastes its own Upload-Post API key and webhook secret
 *   (today's agency behaviour, unchanged). Each `SocialAccount` is its own vendor profile.
 * - `managed`: Alima authenticates with one platform-wide key and isolates the org as its own
 *   Upload-Post profile (`organizations.upload_post_profile`). A business owner never sees an API
 *   key field. Business workspaces are always `managed`; agencies stay `byo` unless a super admin
 *   flips them (via `organizations.publish_mode`).
 *
 * Resolution lives in `App\Contracts\PublishCredentials`; no caller branches on this enum directly.
 */
enum PublishMode: string
{
    case Byo = 'byo';
    case Managed = 'managed';

    public function label(): string
    {
        return match ($this) {
            self::Byo => 'Bring your own key',
            self::Managed => 'Managed by Alima',
        };
    }

    public function isManaged(): bool
    {
        return $this === self::Managed;
    }
}
