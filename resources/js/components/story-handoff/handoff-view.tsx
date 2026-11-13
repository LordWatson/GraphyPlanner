import {
    ArrowRight,
    CheckCircle2,
    Download,
    ExternalLink,
    Share2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import {
    HandoffExtrasList,
    HandoffMediaFrame,
    HandoffNotice,
} from '@/components/story-handoff/handoff-parts';
import type { HandoffData, NextStory } from '@/components/story-handoff/types';
import { useHandoffMedia } from '@/components/story-handoff/use-handoff-media';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/dates';
import { canShareFile, shareStory } from '@/lib/story-share';
import { cn } from '@/lib/utils';

export type HandoffActions = {
    /** Tell the server the poster has started (stops the nudge). Fire and forget. */
    onShared: () => void;
    onPosted: () => void;
    onProblem: (note: string) => void;
};

/** Which request is in flight, so only the button that was tapped says so. */
export type HandoffPending = 'posted' | 'problem' | null;

/** iPhones and iPads save a picture to Photos by pressing and holding it, not by downloading. */
function isAppleTouch(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return (
        /iPhone|iPad|iPod/.test(navigator.userAgent) ||
        (navigator.userAgent.includes('Mac') && navigator.maxTouchPoints > 1)
    );
}

/**
 * Step S-4.3 — the body of the handoff page: three taps, one screen, usable one-handed.
 *
 * 1. **Share to Instagram** copies the link and hands the media to the share sheet.
 * 2. In Instagram the poster adds the stickers from the list (each row has its own Copy).
 * 3. **I've posted it** tells Alima it went out.
 *
 * Which button leads follows where the poster is: Share first; once they have shared (or come
 * back to the tab), "I've posted it". Where the browser can't share a file, or the file is too big
 * to hold in memory, "Save media" leads instead, with the three lines it takes to do it by hand.
 *
 * A handoff that has ended is shown read-only, so a second teammate sees it is done.
 */
export function HandoffView({
    handoff,
    next,
    postUrl,
    actions,
    pending = null,
    errors = {},
}: {
    handoff: HandoffData;
    next?: NextStory | null;
    /** The post in the editor, for a signed-in member. */
    postUrl?: string | null;
    actions: HandoffActions;
    pending?: HandoffPending;
    errors?: Record<string, string>;
}) {
    const ready = handoff.state === 'ready';
    const media = useHandoffMedia(handoff.media, ready);
    const [shared, setShared] = useState(false);
    const [reporting, setReporting] = useState(false);
    const [note, setNote] = useState('');
    const [sharing, setSharing] = useState(false);
    const [shareBroken, setShareBroken] = useState(false);
    const stepsRef = useRef<HTMLDivElement>(null);
    const problemRef = useRef<HTMLButtonElement>(null);
    const refocusRef = useRef(false);
    const startedRef = useRef(false);
    const sharingRef = useRef(false);
    // The media has really left the page (shared, or saved). A closed share sheet doesn't count.
    const handedOverRef = useRef(false);

    const shareable = canShareFile(media.file);
    // Sharing is off the table once we know it: no file will come (too big, or the download
    // failed), the file is here and this browser won't share it, or a share was tried and failed.
    const saveLeads =
        handoff.media !== null &&
        (shareBroken ||
            media.status === 'unavailable' ||
            (media.status === 'ready' && !shareable));

    const moveOn = useCallback(() => {
        setShared(true);
        stepsRef.current?.scrollIntoView({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
            block: 'start',
        });
    }, []);

    // "Cancel" on the problem form puts focus back where it came from.
    useEffect(() => {
        if (!reporting && refocusRef.current) {
            refocusRef.current = false;
            problemRef.current?.focus();
        }
    }, [reporting]);

    // Coming back from Instagram: the next thing to do is the list, then "I've posted it".
    useEffect(() => {
        if (!ready) {
            return;
        }

        const onVisible = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            // A share sheet that never reports back must not lock the Share button for good.
            sharingRef.current = false;
            setSharing(false);

            if (handedOverRef.current) {
                moveOn();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () =>
            document.removeEventListener('visibilitychange', onVisible);
    }, [ready, moveOn]);

    const started = () => {
        if (!startedRef.current) {
            startedRef.current = true;
            actions.onShared();
        }
    };

    const share = async () => {
        if (!media.file || sharingRef.current) {
            return;
        }

        sharingRef.current = true;
        setSharing(true);

        let result: Awaited<ReturnType<typeof shareStory>>;

        try {
            result = await shareStory({
                file: media.file,
                link: handoff.link,
                onStart: started,
            });
        } finally {
            sharingRef.current = false;
            setSharing(false);
        }

        const { outcome, copied } = result;

        if (outcome === 'cancelled') {
            return;
        }

        if (outcome === 'failed' || outcome === 'unsupported') {
            setShareBroken(true);
            toast.error('Sharing didn’t work here. Save the media instead.');

            return;
        }

        handedOverRef.current = true;
        moveOn();

        if (handoff.link) {
            toast.success(
                copied
                    ? 'Link copied. Paste it into the link sticker.'
                    : 'Shared. Copy the link from the list below.',
            );
        }
    };

    const posted = async () => {
        // Nothing was shared or saved from here, so make sure this isn't a stray tap.
        if (
            !shared &&
            !(await confirmDialog({
                title: 'Posted it already?',
                description: `Only say so once the story is live on ${handoff.account.handle}. We can’t check Instagram, so we’ll take your word for it.`,
                confirmLabel: 'I’ve posted it',
            }))
        ) {
            return;
        }

        actions.onPosted();
    };

    if (!ready) {
        return (
            <HandoffEnded
                handoff={handoff}
                next={next}
                postUrl={postUrl}
                pending={pending}
                error={errors.handoff}
                onPosted={actions.onPosted}
            />
        );
    }

    const percent =
        media.progress !== null && media.progress < 1
            ? ` ${Math.round(media.progress * 100)}%`
            : '';

    return (
        <div className="flex flex-col gap-6">
            {handoff.late && (
                <HandoffNotice
                    tone="warning"
                    title={`This was due at ${handoff.due.time}.`}
                >
                    Post it as soon as you can, then tell us below.
                </HandoffNotice>
            )}
            {handoff.early && (
                <HandoffNotice title={`Scheduled for ${handoff.due.time}.`}>
                    You’re early. It’s ready whenever you are.
                </HandoffNotice>
            )}

            <HandoffMediaFrame
                media={handoff.media}
                state={media}
                handle={handoff.account.handle}
            />

            {handoff.media && (
                <div className="flex flex-col gap-3">
                    {!saveLeads && (
                        <Button
                            type="button"
                            size="xl"
                            variant={shared ? 'outline' : 'default'}
                            className="w-full"
                            disabled={media.status !== 'ready' || sharing}
                            aria-busy={media.status === 'loading'}
                            onClick={() => void share()}
                        >
                            <Share2 className="size-5" />
                            <span aria-live="polite">
                                {media.status === 'loading'
                                    ? `Getting the media ready…${percent}`
                                    : shared
                                      ? 'Share again'
                                      : 'Share to Instagram'}
                            </span>
                        </Button>
                    )}
                    <Button
                        asChild
                        size={saveLeads ? 'xl' : 'lg'}
                        variant={saveLeads && !shared ? 'default' : 'outline'}
                        className="w-full"
                    >
                        <a
                            href={handoff.media.url}
                            download={handoff.media.filename}
                            onClick={() => {
                                started();
                                handedOverRef.current = true;
                                setShared(true);
                            }}
                        >
                            <Download className={cn(saveLeads && 'size-5')} />
                            Save media
                        </a>
                    </Button>
                    {saveLeads ? (
                        <div className="text-muted-foreground grid gap-2 text-sm">
                            <ol className="grid list-decimal gap-1 ps-5">
                                <li>Save the media to your phone.</li>
                                <li>Open Instagram and start a story.</li>
                                <li>Pick it from your camera roll.</li>
                            </ol>
                            {isAppleTouch() && (
                                <p className="text-pretty">
                                    {handoff.media.kind === 'image'
                                        ? 'On iPhone, press and hold the picture above and choose Save to Photos.'
                                        : 'On iPhone, open the saved video in Files, tap Share, then Save Video.'}
                                </p>
                            )}
                        </div>
                    ) : (
                        handoff.link && (
                            <p className="text-muted-foreground text-center text-[13px] text-pretty">
                                Sharing copies the link, ready to paste into the
                                link sticker.
                            </p>
                        )
                    )}
                </div>
            )}

            <div ref={stepsRef} className="flex scroll-mt-6 flex-col gap-6">
                <HandoffExtrasList extras={handoff.extras} highlight={shared} />

                {handoff.placement_note && (
                    <figure className="bg-ink text-ink-foreground dark:ring-cream/10 rounded-2xl p-5 dark:ring-1">
                        <figcaption className="eyebrow text-ink-foreground/55">
                            Where to put them
                        </figcaption>
                        <blockquote
                            dir="auto"
                            className="mt-2 text-lg leading-snug font-light whitespace-pre-wrap"
                        >
                            {handoff.placement_note}
                        </blockquote>
                    </figure>
                )}

                <div className="flex flex-col gap-3">
                    <Button
                        type="button"
                        size="xl"
                        variant={shared ? 'default' : 'outline'}
                        className={cn(
                            'w-full',
                            shared &&
                                'bg-success text-success-foreground hover:bg-success/90 active:bg-success/80',
                        )}
                        disabled={pending !== null}
                        onClick={() => void posted()}
                    >
                        <CheckCircle2 className="size-5" />
                        {pending === 'posted' ? 'Saving…' : 'I’ve posted it'}
                    </Button>
                    <InputError message={errors.handoff} />

                    {reporting ? (
                        <form
                            className="border-border bg-card grid gap-3 rounded-2xl border p-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                actions.onProblem(note);
                            }}
                        >
                            <Label htmlFor="handoff-problem">
                                What went wrong?
                            </Label>
                            <Textarea
                                id="handoff-problem"
                                dir="auto"
                                rows={3}
                                maxLength={500}
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                                placeholder="The video wouldn’t upload."
                                autoFocus
                                aria-invalid={Boolean(errors.note)}
                            />
                            <InputError message={errors.note} />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    variant="brand"
                                    className="h-11 flex-1"
                                    disabled={
                                        pending !== null ||
                                        note.trim().length < 3
                                    }
                                >
                                    {pending === 'problem'
                                        ? 'Sending…'
                                        : 'Tell the team'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11"
                                    onClick={() => {
                                        refocusRef.current = true;
                                        setReporting(false);
                                    }}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <Button
                            ref={problemRef}
                            type="button"
                            variant="link"
                            className="h-11 self-center"
                            onClick={() => setReporting(true)}
                        >
                            Something went wrong
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

/** A handoff that has ended, read-only: posted, missed, a problem, or gone out plain. */
function HandoffEnded({
    handoff,
    next,
    postUrl,
    pending,
    error,
    onPosted,
}: {
    handoff: HandoffData;
    next?: NextStory | null;
    postUrl?: string | null;
    pending: HandoffPending;
    error?: string;
    onPosted: () => void;
}) {
    const when = handoff.completed
        ? formatDateTime(handoff.completed.at)
        : null;

    return (
        <div className="flex flex-col gap-6">
            {handoff.state === 'posted' && (
                <div className="border-success/25 bg-success/10 flex items-start gap-3 rounded-2xl border p-5">
                    <CheckCircle2 className="text-success mt-0.5 size-6 shrink-0" />
                    <div className="grid gap-1">
                        <p className="text-success font-semibold">Posted</p>
                        <p className="text-foreground/80 text-sm text-pretty">
                            {when
                                ? `Marked as posted on ${when}`
                                : 'Marked as posted'}
                            {handoff.completed?.by
                                ? ` by ${handoff.completed.by}.`
                                : '.'}{' '}
                            Nothing more to do here.
                        </p>
                    </div>
                </div>
            )}

            {handoff.state === 'missed' && (
                <HandoffNotice
                    tone="warning"
                    title="The time to post this has passed."
                >
                    {handoff.can_confirm_late
                        ? 'If it did go out, tell us below. Otherwise ask your team to send a new reminder.'
                        : 'Ask your team to send a new reminder.'}
                </HandoffNotice>
            )}

            {handoff.state === 'problem' && (
                <HandoffNotice tone="warning" title="A problem was reported.">
                    The team has been told and will pick it up from here.
                </HandoffNotice>
            )}

            {handoff.state === 'published_plain' && (
                <HandoffNotice title="This went out without its extras.">
                    Nobody confirmed it in time, so it was published as a plain
                    story, as the post asked.
                </HandoffNotice>
            )}

            {handoff.state === 'missed' && handoff.can_confirm_late && (
                <div className="flex flex-col gap-2">
                    <Button
                        type="button"
                        size="xl"
                        variant="outline"
                        className="w-full"
                        disabled={pending !== null}
                        onClick={onPosted}
                    >
                        <CheckCircle2 className="size-5" />
                        {pending === 'posted' ? 'Saving…' : 'It did go out'}
                    </Button>
                    <InputError message={error} />
                </div>
            )}

            {(next || postUrl) && (
                <div className="flex flex-col gap-3">
                    {next && (
                        <Button asChild size="xl" className="w-full">
                            <a href={next.url}>
                                Next story
                                {next.handle ? `: ${next.handle}` : ''}
                                <ArrowRight className="size-5" />
                            </a>
                        </Button>
                    )}
                    {postUrl && (
                        <Button
                            asChild
                            variant="link"
                            className="h-11 self-center"
                        >
                            <a href={postUrl}>
                                Open the post in Alima
                                <ExternalLink />
                            </a>
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
