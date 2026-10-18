import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import type {
    CalendarPayload,
    Occurrence,
} from '@/components/content-calendar';
import { ContentCalendar } from '@/components/content-calendar';
import { Page, PageHeader } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import type { UnscheduledPost } from '@/components/unscheduled-posts-sheet';
import { UnscheduledPostsSheet } from '@/components/unscheduled-posts-sheet';
import { formatDate } from '@/lib/dates';
import { calendar } from '@/routes';
import { edit as editPost, reschedule as reschedulePost } from '@/routes/posts';

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
    const count = props.occurrences.length;
    const reschedulable = useMemo(
        () => new Set(props.reschedulablePostIds ?? []),
        [props.reschedulablePostIds],
    );

    // A dropped chip only moves after an explicit confirm, so a slip of the mouse never
    // reschedules anything. The whole post moves: every target shifts by the same days.
    const handleReschedule = async (
        occurrence: Occurrence,
        move: { from: string; to: string; days: number },
    ) => {
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

    return (
        <>
            <Head title="Calendar" />
            <Page>
                <PageHeader
                    eyebrow={`Schedule — ${count} post${count === 1 ? '' : 's'} in view`}
                    title={
                        <>
                            The <em>calendar</em>
                        </>
                    }
                    description="Every scheduled target across every client — by month, week, day, or as a running list."
                    actions={
                        <UnscheduledPostsSheet
                            count={unscheduledCount}
                            page={unscheduled}
                        />
                    }
                />
                <ContentCalendar
                    {...props}
                    baseUrl={calendar().url}
                    postHref={(postId) => editPost(postId)}
                    canReschedule={(occurrence) =>
                        reschedulable.has(occurrence.post_id)
                    }
                    onReschedule={handleReschedule}
                />
            </Page>
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
