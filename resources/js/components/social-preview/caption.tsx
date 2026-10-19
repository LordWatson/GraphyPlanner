import type { ReactNode } from 'react';
import { Fragment, useState } from 'react';
import { foldCaption } from '@/lib/media-format';
import { capabilityOf, usePlatforms } from '@/lib/platforms';
import { textDirection } from '@/lib/text-direction';
import { cn } from '@/lib/utils';

const TOKEN = /(#[\p{L}\p{N}_]+|@[\p{L}\p{N}_.]+|https?:\/\/[^\s]+)/gu;

/**
 * Caption text with hashtags, mentions and links styled the way each app does: URLs are links only
 * where the platform makes them clickable (`links_in_caption`); tags read bold on full-screen media.
 */
export function RichCaption({
    text,
    platform,
    onMedia = false,
}: {
    text: string;
    platform: string;
    /** Sits over full-screen media (TikTok, Reels): tags read bold, not coloured. */
    onMedia?: boolean;
}) {
    // Links read as links only where the platform makes them clickable (the registry).
    const linksUrls = capabilityOf(usePlatforms(), platform).links_in_caption;
    const tagClass = onMedia
        ? 'font-semibold'
        : cn('text-info', platform === 'linkedin' && 'font-semibold');

    return (
        <>
            {text.split(TOKEN).map((part, i) => {
                if (i % 2 === 0) {
                    return <Fragment key={i}>{part}</Fragment>;
                }

                const isUrl = part.startsWith('http');

                if (isUrl && !linksUrls) {
                    return <Fragment key={i}>{part}</Fragment>;
                }

                return (
                    <span
                        key={i}
                        className={cn(
                            tagClass,
                            isUrl && 'break-all underline-offset-2',
                        )}
                    >
                        {part}
                    </span>
                );
            })}
        </>
    );
}

const moreLabels: Record<string, string> = {
    instagram: 'more',
    tiktok: 'more',
    facebook: 'See more',
    linkedin: '…more',
};

/**
 * The caption folded where the app folds it, with the app's own "more" control to expand it.
 * `lead` renders inline before the text (Instagram's bold handle).
 */
export function FoldedCaption({
    text,
    platform,
    lead,
    onMedia = false,
    className,
}: {
    text: string;
    platform: string;
    lead?: ReactNode;
    onMedia?: boolean;
    className?: string;
}) {
    const [expanded, setExpanded] = useState(false);
    const registry = usePlatforms();

    if (!text.trim()) {
        return (
            <p className={cn('leading-snug', className)}>
                {lead}
                <span
                    className={cn(
                        'italic',
                        onMedia ? 'text-cream/70' : 'text-muted-foreground',
                    )}
                >
                    Your caption will appear here…
                </span>
            </p>
        );
    }

    const { visible, truncated } = foldCaption(
        text,
        capabilityOf(registry, platform).caption_fold,
    );
    const shown = expanded || !truncated ? text : visible;

    return (
        <p
            dir={textDirection(text)}
            className={cn(
                'leading-snug break-words whitespace-pre-wrap',
                className,
            )}
        >
            {lead}
            <RichCaption text={shown} platform={platform} onMedia={onMedia} />
            {truncated && (
                <>
                    {!expanded && platform !== 'linkedin' && '… '}
                    {expanded && ' '}
                    <button
                        type="button"
                        onClick={() => setExpanded((current) => !current)}
                        className={cn(
                            'font-medium hover:underline',
                            onMedia ? 'text-cream' : 'text-muted-foreground',
                        )}
                    >
                        {expanded ? 'less' : (moreLabels[platform] ?? 'more')}
                    </button>
                </>
            )}
        </p>
    );
}
