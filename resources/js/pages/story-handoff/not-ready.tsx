import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, CheckCircle2, Clock4 } from 'lucide-react';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';

type State = 'not_due' | 'posted' | 'missed' | 'none' | 'open';

/**
 * Step S-4.5 — a member opened a Story's handoff from inside Alima (the bell, Needs attention, the
 * editor) and there is nothing to hand over right now. Says why, in one line, and offers the post.
 */
export default function StoryHandoffNotReady({
    state,
    account,
    due,
    postUrl,
}: {
    state: State;
    account: { handle: string | null; platform_label: string | null };
    due: { date: string; time: string; timezone: string } | null;
    postUrl: string;
}) {
    const handle = account.handle ?? 'This account';
    const when = due
        ? `${formatSchedule(due.date, due.time)} (${due.timezone.replace(/_/g, ' ')})`
        : null;

    const copy = {
        not_due: {
            icon: CalendarClock,
            title: 'Not due yet.',
            description: when
                ? `${handle}’s story is posted by hand on ${when}. We’ll remind whoever is posting then.`
                : `${handle}’s story has no time yet. Give it one and schedule the post.`,
        },
        posted: {
            icon: CheckCircle2,
            title: 'Already posted.',
            description: `${handle}’s story has been marked as posted. Nothing more to do.`,
        },
        missed: {
            icon: Clock4,
            title: 'The time has passed.',
            description: `Nobody confirmed ${handle}’s story was posted. Open the post to send a new reminder, or mark it as posted if it did go out.`,
        },
        none: {
            icon: CalendarClock,
            title: 'Nothing to post here.',
            description: `${handle}’s story isn’t waiting on anybody right now.`,
        },
        open: {
            icon: CalendarClock,
            title: 'Nothing to post here.',
            description: `${handle}’s story isn’t waiting on anybody right now.`,
        },
    }[state];

    return (
        <>
            <Head title="Story to post" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Story"
                    title={
                        <>
                            Post by <em>hand</em>.
                        </>
                    }
                    description="Instagram only allows links and stickers from its own app, so a story with extras is posted by a person."
                />
                <EmptyState
                    icon={copy.icon}
                    title={copy.title}
                    description={copy.description}
                    action={
                        <Button asChild variant="outline">
                            <Link href={postUrl}>
                                <ArrowLeft />
                                Open the post
                            </Link>
                        </Button>
                    }
                />
            </Page>
        </>
    );
}
