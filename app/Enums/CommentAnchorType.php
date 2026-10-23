<?php

namespace App\Enums;

/**
 * Step M-6.4 — what a visual-feedback comment is pinned to.
 */
enum CommentAnchorType: string
{
    /** A point on an image asset: anchor = {asset_id, x, y} with x/y as 0–1 fractions. */
    case Image = 'image';

    /** A moment in a video asset: anchor = {asset_id, t} with t in seconds. */
    case Video = 'video';

    /** A range of the caption: anchor = {channel, start, end, quote}. */
    case Caption = 'caption';
}
