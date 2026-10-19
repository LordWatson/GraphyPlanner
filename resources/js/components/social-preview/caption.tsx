import type { ReactNode } from 'react';
import { Fragment, useState } from 'react';
import { foldCaption } from '@/lib/media-format';
import { textDirection } from '@/lib/text-direction';
import { cn } from '@/lib/utils';

const TOKEN = /(#[\p{L}\p{N}_]+|@[\p{L}\p{N}_.]+|https?:\/\/[^\s]+)/gu;

/**
 * Caption text with hashtags, mentions and links styled the way each app does: Instagram and
 * TikTok don't link URLs in captions, Facebook and LinkedIn do; TikTok sets tags bold on video.
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
    const linksUrls = platform === 'facebook' || platform === 'linkedin';
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

    const { visible, truncated } = foldCaption(text, platform);
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
