import { Head } from '@inertiajs/react';
import type { CalendarPayload } from '@/components/content-calendar';
import { ContentCalendar } from '@/components/content-calendar';
import { CalendarDays } from 'lucide-react';
import { ChapterHero, HeroStat, Page } from '@/components/page';
import { calendar as portalCalendar } from '@/routes/portal';
import { show as portalPostsShow } from '@/routes/portal/posts';

/**
 * The client portal's calendar: the shared `ContentCalendar` in single-client mode (no client
 * filter; chips lead with the caption), fed by `PortalCalendarController`, which only ever returns
 * this client's client-visible posts.
 */
export default function PortalCalendar({
    client,
    ...payload
}: CalendarPayload & { client: { name: string } }) {
    const count = payload.occurrences.length;

    return (
        <>
            <Head title="Calendar — Client portal" />
            <Page>
                <ChapterHero
                    eyebrow={`${client.name} — ${count} post${count === 1 ? '' : 's'} on the calendar`}
                    title={
                        <>
                            Your <em>calendar</em>.
                        </>
                    }
                    description="When and where every post is going out — in each account's own timezone, or the studio's."
                    aside={
                        <HeroStat
                            icon={CalendarDays}
                            eyebrow="Scheduled"
                            value={String(count).padStart(2, '0')}
                            caption={count === 1 ? 'Post' : 'Posts'}
                        />
                    }
                />
                <ContentCalendar
                    {...payload}
                    multiClient={false}
                    baseUrl={portalCalendar().url}
                    postHref={(postId) => portalPostsShow(postId)}
                />
            </Page>
        </>
    );
}
