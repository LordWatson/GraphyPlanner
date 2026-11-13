import { ChevronRight } from 'lucide-react';
import { PlatformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { formatSchedule } from '@/lib/dates';
import { cn } from '@/lib/utils';

/** One row of `GetStoryQueueAction`: a Story somebody posts by hand. */
export type StoryQueueRow = {
    handoff_id: number | null;
    post_id: number;
    target_id: number;
    /** `ready` = its handoff is open and due; `upcoming` = due inside the next day. */
    state: 'ready' | 'upcoming';
    client_name: string | null;
    platform: string | null;
    handle: string | null;
    due_at: string;
    due: { date: string; time: string; timezone: string };
    expires_at: string | null;
    posted_by: 'team' | 'client';
    poster_name: string | null;
    /** A short summary of the extras, e.g. "a link and a poll". */
    extras: string | null;
    /** The signed-in `posts.story-handoff.open` route. Never a token link. */
    url: string;
};

/** Who is on the hook for a Story, in words. */
export function storyPosterLabel(story: StoryQueueRow): string {
    if (story.posted_by === 'client') {
        return 'The client';
    }

    return story.poster_name ?? 'Everyone on the team';
}

/**
 * Steps S-4.4 / S-5.2 — a Story to post by hand, as one thumb-sized link (the queue page, Needs
 * attention and My work all list them the same way). A ready Story leads with its "Ready to post"
 * pill; an upcoming one is muted and says when it is due. The link is a plain anchor: the open
 * route redirects to the handoff page, which lives outside the studio shell.
 */
export function StoryRow({
    story,
    showPoster = false,
    className,
}: {
    story: StoryQueueRow;
    /** Name who is posting it (lists that show other people's Stories too). */
    showPoster?: boolean;
    className?: string;
}) {
    const ready = story.state === 'ready';
    const handle = story.handle ?? 'Story';
    const when = formatSchedule(story.due.date, story.due.time);
    const details = [
        story.client_name,
        story.extras ? `With ${story.extras}` : null,
        showPoster ? `Posting: ${storyPosterLabel(story)}` : null,
    ].filter(Boolean);

    return (
        <a
            href={story.url}
            className={cn(
                'group hover:bg-muted/50 focus-visible:ring-ring flex min-h-14 items-center gap-3 px-4 py-3 transition-colors outline-none focus-visible:ring-3 focus-visible:ring-inset sm:px-5',
                className,
            )}
        >
            <PlatformIcon
                platform={story.platform}
                className={cn(!ready && 'opacity-60')}
            />
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span
                    className={cn(
                        'truncate text-sm font-medium',
                        ready
                            ? 'group-hover:text-primary'
                            : 'text-muted-foreground',
                    )}
                >
                    {handle}
                </span>
                {details.length > 0 && (
                    <span className="text-muted-foreground truncate text-xs">
                        {details.join(' · ')}
                    </span>
                )}
                <span className="text-muted-foreground font-mono text-[11px] tabular-nums sm:hidden">
                    {ready ? when : `Due ${when}`}
                </span>
                {ready && (
                    <StatusPill
                        kind="storyHandoff"
                        value="ready_to_post"
                        className="mt-1 self-start sm:hidden"
                    />
                )}
            </span>
            <span className="text-muted-foreground hidden shrink-0 font-mono text-xs tabular-nums sm:block">
                {ready ? when : `Due ${when}`}
            </span>
            {ready && (
                <StatusPill
                    kind="storyHandoff"
                    value="ready_to_post"
                    className="hidden shrink-0 sm:inline-flex"
                />
            )}
            <span
                aria-hidden
                className={cn(
                    'flex shrink-0 items-center gap-1 text-xs font-semibold',
                    ready ? 'text-foreground' : 'text-muted-foreground/60',
                )}
            >
                {ready && (
                    <span className="hidden md:inline">Open the story</span>
                )}
                <ChevronRight className="size-4" />
            </span>
        </a>
    );
}
