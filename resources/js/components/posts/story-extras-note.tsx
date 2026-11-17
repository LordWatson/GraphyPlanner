import { Hand } from 'lucide-react';
import { Panel } from '@/components/page';
import { cn } from '@/lib/utils';

/** What `StoryChannelPresenter::clientStory()` sends for a Story that is posted by hand. */
export type ClientStory = {
    extras: { key: string; label: string; value: string }[];
    /** "a link and a poll". */
    summary: string;
    placement_note: string | null;
    posted_by: 'team' | 'client';
};

/**
 * Step S-1.5 — a Story's link and stickers, read-only, beside its preview on the pages a client
 * approves from (the review link and the portal). They are approving these along with the
 * creative, so they are listed in full; and because Instagram only allows them from its own app,
 * the note says the Story will be posted by hand — and by whom.
 */
export function StoryExtrasNote({
    stories,
}: {
    stories: { handle: string | null; story: ClientStory | null | undefined }[];
}) {
    const byHand = stories.filter(
        (item): item is { handle: string | null; story: ClientStory } =>
            Boolean(item.story),
    );

    if (byHand.length === 0) {
        return null;
    }

    return (
        <>
            {byHand.map(({ handle, story }, index) => (
                <Panel
                    key={`${handle}-${index}`}
                    icon={Hand}
                    eyebrow="Posted by hand"
                    title="Story extras"
                    description={
                        <span className="text-pretty">
                            {handle ? `${handle}’s story` : 'This story'} has{' '}
                            {story.summary || 'extras'}, so it will be posted by
                            hand at the scheduled time.{' '}
                            {story.posted_by === 'client' && (
                                <span className="text-foreground font-medium">
                                    We’ll send you a link to post it yourselves.
                                </span>
                            )}
                        </span>
                    }
                    bodyClassName={cn(
                        'flex flex-col gap-3 pt-2.5 sm:pt-2.5',
                        story.extras.length === 0 &&
                            !story.placement_note &&
                            'hidden',
                    )}
                >
                    {story.extras.length > 0 && (
                        <dl className="divide-border/70 divide-y">
                            {story.extras.map((extra, i) => (
                                <div
                                    key={`${extra.key}-${i}`}
                                    className="grid grid-cols-[minmax(0,1fr)] gap-0.5 py-2.5 sm:grid-cols-[120px_minmax(0,1fr)] sm:gap-3"
                                >
                                    <dt className="eyebrow text-muted-foreground sm:pt-0.5">
                                        {extra.label}
                                    </dt>
                                    <dd
                                        dir="auto"
                                        className="text-sm [overflow-wrap:anywhere]"
                                    >
                                        {extra.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}

                    {story.placement_note && (
                        <p
                            dir="auto"
                            className="bg-muted/60 text-foreground/80 rounded-xl px-3.5 py-2.5 text-[13px] [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >
                            <span className="eyebrow text-muted-foreground mb-1 block">
                                Placement
                            </span>
                            {story.placement_note}
                        </p>
                    )}
                </Panel>
            ))}
        </>
    );
}
