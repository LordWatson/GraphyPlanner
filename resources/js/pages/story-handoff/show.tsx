import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { platformIcon } from '@/components/platform-icon';
import { PublicShell, PublicTitle } from '@/components/public-shell';
import { HandoffView } from '@/components/story-handoff/handoff-view';
import type { HandoffPending } from '@/components/story-handoff/handoff-view';
import type { HandoffData, NextStory } from '@/components/story-handoff/types';
import { formatSchedule } from '@/lib/dates';
import { http } from '@/lib/http';
import { posted, problem, shared } from '@/routes/story-handoff';

/**
 * Step S-4.3 — the Story handoff page (`/story/:token`). Opened from the "Time to post." push,
 * email or WhatsApp on a phone, with no sign-in: the link is the authorisation and opens this one
 * Story and nothing else. A token-link page like the client review, so it wears `PublicShell`.
 */
export default function StoryHandoffShow({
    token,
    handoff,
    next = null,
    postUrl = null,
}: {
    token: string;
    handoff: HandoffData;
    next?: NextStory | null;
    can?: { openPost: boolean };
    postUrl?: string | null;
}) {
    const [pending, setPending] = useState<HandoffPending>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const Icon = platformIcon(handoff.account.platform);

    const send = (
        action: NonNullable<HandoffPending>,
        url: string,
        data: Record<string, string> = {},
    ) => {
        setPending(action);
        setErrors({});
        router.post(url, data, {
            preserveScroll: true,
            onError: (failed) => setErrors(failed),
            onFinish: () => setPending(null),
        });
    };

    const title = {
        ready: (
            <>
                Post this <em>story</em>.
            </>
        ),
        posted: (
            <>
                Posted. <em>Nice</em> one.
            </>
        ),
        missed: (
            <>
                This one was <em>missed</em>.
            </>
        ),
        problem: (
            <>
                We’re <em>on</em> it.
            </>
        ),
        published_plain: (
            <>
                It’s <em>out</em>.
            </>
        ),
        cancelled: <>This link has ended.</>,
    }[handoff.state];

    return (
        <>
            <Head title={`Post a story — ${handoff.account.handle}`}>
                <meta name="robots" content="noindex, nofollow" />
                <meta name="referrer" content="no-referrer" />
            </Head>
            <PublicShell context={handoff.client.name ?? undefined}>
                <PublicTitle kicker="Story to post" title={title}>
                    <p className="text-foreground flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[17px] leading-snug">
                        {handoff.state === 'ready' ? 'Post to' : 'For'}
                        <span className="inline-flex max-w-full min-w-0 items-center gap-1.5 font-semibold">
                            <Icon className="size-[18px] shrink-0" />
                            <span className="truncate">
                                {handoff.account.handle}
                            </span>
                        </span>
                        on {handoff.account.platform_label}.
                    </p>
                    <p className="mt-1 font-mono text-xs tabular-nums">
                        Due {formatSchedule(handoff.due.date, handoff.due.time)}{' '}
                        <span className="text-muted-foreground">
                            ({handoff.due.timezone.replace(/_/g, ' ')})
                        </span>
                    </p>
                </PublicTitle>

                <HandoffView
                    handoff={handoff}
                    next={next}
                    postUrl={postUrl}
                    pending={pending}
                    errors={errors}
                    actions={{
                        onShared: () =>
                            void http.post(
                                shared.url(token),
                                {},
                                { keepalive: true },
                            ),
                        onPosted: () => send('posted', posted.url(token)),
                        onProblem: (note) =>
                            send('problem', problem.url(token), { note }),
                    }}
                />
            </PublicShell>
        </>
    );
}
