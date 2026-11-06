<?php

namespace App\Support\Posts;

use App\Models\Post;
use App\Models\PostTarget;

/**
 * Step CG-5.1 (B-11.1) — the one place that decides whether a paid-partnership post actually says
 * so. Used by the pre-schedule checklist (which blocks scheduling) and by the editor's copy, so the
 * rule the owner reads is the rule that is enforced.
 *
 * It is checked against each channel's **published** caption ({@see PostTarget::publishedCaption()}),
 * which is what makes it per-platform without a per-platform rule: a platform that doesn't take
 * hashtags (Google Business) has them composed out of the caption, so `#ad` sitting in the hashtag
 * field doesn't count there and the owner has to write the disclosure into the text. A platform that
 * does take them accepts either.
 *
 * ASA/FTC-style wording is deliberately generous (and matched case-insensitively) rather than
 * prescriptive: the law asks for a clear, prominent disclosure, and a creator's own phrasing —
 * "paid partnership with …", "#gifted", "#ad" — is theirs to choose. We only refuse silence.
 */
final class PartnershipDisclosure
{
    /**
     * Any one of these in the published caption counts as a disclosure.
     *
     * @var list<string>
     */
    public const MARKERS = [
        '#ad', '#advert', '#advertisement', '#sponsored', '#sponsoredpost', '#paidpartnership',
        '#paidpartner', '#paidad', '#gifted', '#giftedbyn', '#affiliate', '#brandpartner',
        'paid partnership', 'paid promotion', 'paid collaboration', 'sponsored by',
        'advertisement', 'in partnership with', 'gifted by',
    ];

    /**
     * The markers worth putting in front of the owner as a hint, shortest and most recognised first.
     *
     * @var list<string>
     */
    public const SUGGESTED = ['#ad', '#sponsored', '#paidpartnership', '#gifted'];

    public static function isDisclosed(string $publishedCaption): bool
    {
        $text = mb_strtolower($publishedCaption);

        foreach (self::MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this post have to disclose? Only when the owner marked it as a paid partnership — we
     * never guess from a brand mention, because a wrong guess either blocks an honest post or
     * implies a declaration nobody made.
     */
    public static function isRequired(Post $post): bool
    {
        // Cast, not a bare read: a post the checklist sees before it has been reloaded (grid
        // production builds posts in memory) has no value for the column yet, only the DB default.
        return (bool) $post->is_paid_partnership;
    }
}
