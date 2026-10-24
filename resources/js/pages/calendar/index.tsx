import { Head, router } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { CalendarNewPostDialog } from '@/components/calendar/calendar-new-post-dialog';
import type { NewPostSlot } from '@/components/calendar/calendar-new-post-dialog';
import { confirmDialog } from '@/components/confirm-dialog';
import type {
    CalendarPayload,
    Occurrence,
    PostMove,
    TargetMove,
} from '@/components/content-calendar';
import { ContentCalendar } from '@/components/content-calendar';
import { ChapterHero, HeroStat, Page } from '@/components/page';
import { useWorkspace } from '@/hooks/use-workspace';
import type { Paginated } from '@/components/pagination';
import type { UnscheduledPost } from '@/components/unscheduled-posts-sheet';
import { UnscheduledPostsSheet } from '@/components/unscheduled-posts-sheet';
import { formatDate } from '@/lib/dates';
import { calendar } from '@/routes';
import { edit as editPost, reschedule as reschedulePost } from '@/routes/posts';
import { reschedule as rescheduleTarget } from '@/routes/posts/targets';

/**
 * The internal (staff) content calendar. Client portal contacts are redirected to
 * `portal/calendar` by `RedirectClientReviewersToPortal`, so this always renders multi-client.
 */
type CalendarPageProps = CalendarPayload & {
    unscheduledCount: number;
    unscheduled?: Paginated<UnscheduledPost>;
};

export default function Calendar({
    unscheduledCount,
    unscheduled,
    ...props
}: CalendarPageProps) {
    // Step B-4.1: a business workspace has one brand, so the calendar hides the client filter and
    // leads each chip with its caption instead of a client name.
    const { isBusiness } = useWorkspace();
    const count = props.occurrences.length;
    const reschedulable = useMemo(
        () => new Set(props.reschedulablePostIds ?? []),
        [props.reschedulablePostIds],
    );
    const [newPostSlot, setNewPostSlot] = useState<NewPostSlot | null>(null);

    // A dropped chip only moves after an explicit confirm, so a slip of the mouse never
    // reschedules anything. The month view moves the whole post: every target shifts by the
    // same days.
    const handleReschedule = async (occurrence: Occurrence, move: PostMove) => {
        const channels = props.occurrences.filter(
            (other) => other.post_id === occurrence.post_id,
        ).length;
        const name =
            occurrence.client_name ?? occurrence.master_caption ?? 'This post';
        const shift = `${Math.abs(move.days)} day${Math.abs(move.days) === 1 ? '' : 's'} ${move.days > 0 ? 'later' : 'earlier'}`;

        const confirmed = await confirmDialog({
            title: `Move this post to ${formatDate(move.to)}?`,
            description: `${name} moves from ${formatDate(move.from)} to ${formatDate(move.to)} (${shift}). ${
                channels > 1
                    ? `All ${channels} channels move together and keep their scheduled times.`
                    : 'It keeps its scheduled time.'
            }`,
            confirmLabel: 'Move post',
        });

        if (!confirmed) return;

        router.post(
            reschedulePost(occurrence.post_id).url,
            { days: move.days },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    toast.error(errors.days ?? 'The post couldn’t be moved.'),
            },
        );
    };

    // Step M-5.7 / I-5.1: the week/day time grid moves one channel to an exact slot, leaving the
    // post's other channels where they are.
    const handleRescheduleTarget = async (
        occurrence: Occurrence,
        move: TargetMove,
    ) => {
        const name =
            occurrence.client_name ?? occurrence.master_caption ?? 'This post';
        const channel =
            occurrence.handle ?? occurrence.platform ?? 'this channel';
        const when = move.time
            ? `${formatDate(move.date)} at ${move.time}`
            : formatDate(move.date);

        const confirmed = await confirmDialog({
            title: `Move ${channel} to ${when}?`,
            description: `Only ${name}’s ${channel} channel moves. Its other channels stay where they are.`,
            confirmLabel: 'Move channel',
        });

        if (!confirmed) return;

        router.post(
            rescheduleTarget(occurrence.target_id).url,
            {
                scheduled_local_date: move.date,
                scheduled_local_time: move.time,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    toast.error(
                        errors.scheduled_local_date ??
                            'The channel couldn’t be moved.',
                    ),
            },
        );
    };

    return (
        <>
            <Head title="Calendar" />
            <Page>
                <ChapterHero
                    eyebrow={`Schedule — ${count} post${count === 1 ? '' : 's'} in view`}
                    title={
                        <>
                            The <em>calendar</em>.
                        </>
                    }
                    description="Every scheduled target across every client — by month, week, day, or as a running list."
                    aside={
                        <HeroStat
                            icon={CalendarDays}
                            eyebrow="In view"
                            value={String(count).padStart(2, '0')}
                            caption={
                                count === 1
                                    ? 'Scheduled target'
                                    : 'Scheduled targets'
                            }
                        />
                    }
                />
                <div className="animate-rise flex justify-end [animation-delay:80ms]">
                    <UnscheduledPostsSheet
                        count={unscheduledCount}
                        page={unscheduled}
                    />
                </div>
                <ContentCalendar
                    {...props}
                    multiClient={!isBusiness}
                    baseUrl={calendar().url}
                    postHref={(postId) => editPost(postId)}
                    canReschedule={(occurrence) =>
                        reschedulable.has(occurrence.post_id)
                    }
                    onReschedule={handleReschedule}
                    onRescheduleTarget={handleRescheduleTarget}
                    onCreateAt={(slot) => setNewPostSlot(slot)}
                />
            </Page>
            <CalendarNewPostDialog
                slot={newPostSlot}
                clients={props.filterOptions.clients}
                onClose={() => setNewPostSlot(null)}
            />
        </>
    );
}

Calendar.layout = {
    breadcrumbs: [
        {
            title: 'Calendar',
            href: calendar(),
        },
    ],
};
