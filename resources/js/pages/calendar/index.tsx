import { Head } from '@inertiajs/react';
import type { CalendarPayload } from '@/components/content-calendar';
import { ContentCalendar } from '@/components/content-calendar';
import { Page, PageHeader } from '@/components/page';
import { calendar } from '@/routes';
import { edit as editPost } from '@/routes/posts';

/**
 * The internal (staff) content calendar. Client portal contacts are redirected to
 * `portal/calendar` by `RedirectClientReviewersToPortal`, so this always renders multi-client.
 */
export default function Calendar(props: CalendarPayload) {
    const count = props.occurrences.length;

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
                />
                <ContentCalendar
                    {...props}
                    baseUrl={calendar().url}
                    postHref={(postId) => editPost(postId)}
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
