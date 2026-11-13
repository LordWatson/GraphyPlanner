<?php

namespace App\Enums;

/**
 * Step S-1.2 (design decision D7) — who presses share in Instagram for a Story with extras: the
 * agency's own team, or the client themselves. Stored per channel
 * (`post_targets.handoff_recipient`, null = the client's default) and as that default
 * (`clients.story_handoff_recipient`).
 */
enum StoryHandoffRecipient: string
{
    case Team = 'team';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'Our team',
            self::Client => 'The client',
        };
    }
}
