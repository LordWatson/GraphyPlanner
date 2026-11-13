<?php

namespace App\Enums;

/**
 * Step S-2.2 — how a Story handoff ended. Null on the row means it is still open.
 */
enum StoryHandoffOutcome: string
{
    /** The poster confirmed it went out. */
    case Posted = 'posted';

    /** Nobody confirmed inside the window. The channel failed and can be retried. */
    case Missed = 'missed';

    /** Superseded: a retry, a recall, or the post left `publishing`. The channel is untouched. */
    case Cancelled = 'cancelled';

    /** Step S-6.2: nobody confirmed, and the post asked to go out without its extras instead. */
    case PublishedPlain = 'published_plain';

    /** The poster said something went wrong. The channel failed with their note. */
    case Problem = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted by hand',
            self::Missed => 'Not posted in time',
            self::Cancelled => 'Cancelled',
            self::PublishedPlain => 'Published without extras',
            self::Problem => 'Problem reported',
        };
    }
}
