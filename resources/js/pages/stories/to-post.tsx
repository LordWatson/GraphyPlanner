import { Head, Link } from '@inertiajs/react';
import { CalendarDays, CheckCircle2 } from 'lucide-react';
import { EmptyState, Page, PageHeader } from '@/components/page';
import type { StoryQueueRow } from '@/components/stories/story-row';
import { StoryRow } from '@/components/stories/story-row';
import { Button } from '@/components/ui/button';
import { calendar, dashboard } from '@/routes';
import { queue as storiesQueue } from '@/routes/stories';

/**
 * Step S-4.4 — the poster's queue: every Story the signed-in member posts by hand, in one sitting,
 * on a phone. Ready ones first (each row opens its handoff page), then what is coming up in the next day.
 */
export default function StoriesToPost({
    stories,
}: {
    stories: StoryQueueRow[];
}) {
    const ready = stories.filter((story) => story.state === 'ready');
    const upcoming = stories.filter((story) => story.state === 'upcoming');

    return (
        <>
            <Head title="Stories to post" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={
                        ready.length > 0
                            ? `${String(ready.length).padStart(2, '0')} ready now`
                            : 'Posted by hand'
                    }
                    title={
                        <>
                            Stories to <em>post</em>.
                        </>
                    }
                    description="Stories with a link or a sticker go out from the Instagram app. Open one, share it, then tell us it's posted."
                />

                {stories.length === 0 && (
                    <EmptyState
                        icon={CheckCircle2}
                        title="All posted."
                        description="No stories are waiting for you. We'll tell you when the next one is ready."
                        action={
                            <Button variant="outline" asChild>
                                <Link href={calendar()}>
                                    <CalendarDays />
                                    Back to the calendar
                                </Link>
                            </Button>
                        }
                    />
                )}

                {ready.length > 0 && (
                    <StoryList
                        id="stories-ready"
                        title="Ready now"
                        count={ready.length}
                        stories={ready}
                    />
                )}

                {upcoming.length > 0 && (
                    <StoryList
                        id="stories-later"
                        title="Coming up"
                        hint="Not ready yet. We’ll tell you when it’s time."
                        count={upcoming.length}
                        stories={upcoming}
                    />
                )}
            </Page>
        </>
    );
}

function StoryList({
    id,
    title,
    hint,
    count,
    stories,
}: {
    id: string;
    title: string;
    hint?: string;
    count: number;
    stories: StoryQueueRow[];
}) {
    return (
        <section
            aria-labelledby={id}
            className="animate-rise flex flex-col gap-3"
        >
            <div className="border-foreground/85 flex flex-col gap-1 border-t pt-4">
                <h2
                    id={id}
                    className="flex items-baseline gap-2.5 text-[17px] font-semibold tracking-[-0.01em]"
                >
                    {title}
                    <span className="font-mono text-[13px] font-medium tabular-nums">
                        {String(count).padStart(2, '0')}
                    </span>
                </h2>
                {hint && (
                    <p className="text-muted-foreground text-[13px]">{hint}</p>
                )}
            </div>
            <ul className="border-border/80 bg-card divide-border/70 flex flex-col divide-y overflow-hidden rounded-2xl border shadow-sm">
                {stories.map((story) => (
                    <li key={story.target_id}>
                        <StoryRow story={story} className="sm:min-h-16" />
                    </li>
                ))}
            </ul>
        </section>
    );
}

StoriesToPost.layout = {
    breadcrumbs: [
        { title: 'Home', href: dashboard() },
        { title: 'Stories to post', href: storiesQueue() },
    ],
};
