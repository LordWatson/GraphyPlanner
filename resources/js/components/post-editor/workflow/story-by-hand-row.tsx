import { router } from '@inertiajs/react';
import { CheckCheck, Smartphone } from 'lucide-react';
import { useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import type { TargetData } from '@/components/post-editor/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { formatSchedule } from '@/lib/dates';
import { markPosted, open as openHandoff } from '@/routes/posts/story-handoff';

/**
 * Confirm, then mark a Story as posted by hand from the editor (Step S-5.2). Alima can't see
 * Instagram, so this is the member's word for it — hence the confirm.
 */
export async function markStoryPosted(
    postId: number,
    target: TargetData,
    onFinish?: () => void,
): Promise<void> {
    const confirmed = await confirmDialog({
        title: 'Mark this story as posted?',
        description: `Only do this once the story is live on ${target.handle ?? 'Instagram'}. We can’t check Instagram, so we’ll take your word for it.`,
        confirmLabel: 'Mark as posted',
    });

    if (!confirmed) {
        onFinish?.();

        return;
    }

    router.post(
        markPosted.url([postId, target.id]),
        {},
        { preserveScroll: true, onFinish },
    );
}

/**
 * Step S-5.1 / S-5.2 — the Stories on this post that are posted by hand, in the workflow panel.
 * One that is due gets the two things a member can do about it from here: open its handoff (the
 * media, the extras, "I've posted it") or mark it as posted. One that isn't due yet just says
 * when, so nobody wonders why it hasn't gone out with the rest.
 */
export function StoryByHandRow({
    postId,
    targets,
    canAct,
}: {
    postId: number;
    targets: TargetData[];
    /** The member may open the handoff or mark it posted (`PostPolicy::postStoryByHand`). */
    canAct: boolean;
}) {
    const [marking, setMarking] = useState<number | null>(null);

    if (targets.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-2.5">
            {targets.map((target) => {
                const ready = target.handoff_state === 'ready_to_post';

                return (
                    <li
                        key={target.id}
                        className="border-border bg-muted/40 flex flex-wrap items-center gap-x-3 gap-y-2.5 rounded-xl border px-3 py-3"
                    >
                        <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-full">
                            <Smartphone className="size-4" />
                        </span>
                        <div className="grid min-w-0 flex-1 basis-48 gap-1">
                            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-medium">
                                <span className="truncate">
                                    {target.handle
                                        ? `${target.handle} story`
                                        : 'Story'}
                                </span>
                                <StatusPill
                                    kind="storyHandoff"
                                    value={
                                        target.handoff_state ??
                                        'needs_hand_posting'
                                    }
                                />
                            </p>
                            <p className="text-muted-foreground text-xs text-pretty">
                                {ready
                                    ? 'It has a link or stickers, so someone posts it from the Instagram app. Open it on your phone to share it.'
                                    : `Posted by hand on ${formatSchedule(target.scheduled_local_date, target.scheduled_local_time, 'its scheduled time')}. We’ll remind whoever is posting then.`}
                            </p>
                        </div>
                        {ready && canAct && (
                            <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                                <Button
                                    asChild
                                    variant="brand"
                                    size="sm"
                                    className="h-11 flex-1 basis-36 sm:h-8 sm:flex-none sm:basis-auto"
                                >
                                    <a
                                        href={openHandoff.url([
                                            postId,
                                            target.id,
                                        ])}
                                    >
                                        <Smartphone />
                                        Open the story
                                    </a>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="h-11 flex-1 basis-36 sm:h-8 sm:flex-none sm:basis-auto"
                                    disabled={marking === target.id}
                                    onClick={() => {
                                        setMarking(target.id);
                                        void markStoryPosted(
                                            postId,
                                            target,
                                            () => setMarking(null),
                                        );
                                    }}
                                >
                                    <CheckCheck />
                                    Mark as posted
                                </Button>
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
