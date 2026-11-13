import { useId } from 'react';
import type { StoryHandoffOptions } from '@/components/post-editor/types';
import { Checkbox } from '@/components/ui/checkbox';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { StoryHandoffRecipient } from '@/lib/story-extras';
import { describeWindow } from '@/lib/story-extras';

/** The team member a Story's reminder goes to: the assignee, else the creator, else nobody. */
export function storyPosterName(
    options: StoryHandoffOptions,
    assigneeId: string,
): string | null {
    const find = (id: number | null) =>
        options.posters.find((poster) => poster.id === id)?.name ?? null;

    return (
        find(assigneeId ? Number(assigneeId) : null) ?? find(options.creator_id)
    );
}

/**
 * Who presses share in Instagram: the team or the client, in the house selection style, with one
 * line saying exactly who will hear about it. A business workspace has no client to hand it to,
 * so there the choice is hidden and it is always the team.
 */
export function WhoPostsIt({
    id,
    recipient,
    onChange,
    options,
    assigneeId,
    clientName,
}: {
    id: string;
    /** The channel's own choice; null follows the client's default. */
    recipient: StoryHandoffRecipient | null;
    onChange: (recipient: StoryHandoffRecipient | null) => void;
    options: StoryHandoffOptions;
    assigneeId: string;
    clientName: string;
}) {
    const effective: StoryHandoffRecipient = options.can_choose_recipient
        ? (recipient ?? options.default_recipient)
        : 'team';
    const posterName = storyPosterName(options, assigneeId);
    const choices: { value: StoryHandoffRecipient; label: string }[] = [
        { value: 'team', label: 'Our team' },
        { value: 'client', label: clientName },
    ];

    return (
        <div className="border-border/70 grid gap-3 border-t pt-4">
            <span id={id} className="text-sm leading-none font-medium">
                Who posts it
            </span>
            {options.can_choose_recipient && (
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={effective}
                    // Radix sends '' when the selected item is tapped again: there is always one.
                    onValueChange={(value) =>
                        value &&
                        onChange(
                            value === options.default_recipient
                                ? null
                                : (value as StoryHandoffRecipient),
                        )
                    }
                    aria-labelledby={id}
                    className="w-fit max-w-full"
                >
                    {choices.map((choice) => (
                        <ToggleGroupItem
                            key={choice.value}
                            value={choice.value}
                            // The client's name may be long: it gives way, the team's doesn't.
                            className="h-11 px-3.5 last:shrink sm:h-9"
                            title={
                                choice.value === 'client'
                                    ? clientName
                                    : undefined
                            }
                        >
                            <span className="max-w-40 min-w-0 truncate">
                                {choice.label}
                            </span>
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            )}
            <p
                className="text-muted-foreground text-[13px] text-pretty"
                aria-live="polite"
            >
                {effective === 'client' ? (
                    options.client_can_be_notified ? (
                        <>
                            We’ll send {clientName} a link to post it themselves
                            when it’s due.
                        </>
                    ) : (
                        <span className="text-warning">
                            {clientName} has no contact to send it to yet, so
                            your team will be asked instead.
                        </span>
                    )
                ) : posterName ? (
                    <>
                        <span className="text-foreground font-medium">
                            {posterName}
                        </span>{' '}
                        gets the reminder when it’s due.
                    </>
                ) : (
                    <>
                        No one is assigned, so everyone on the team gets the
                        reminder.
                    </>
                )}
            </p>
        </div>
    );
}

/**
 * Step S-6.2 — "if nobody posts, publish it plain". Off unless a person turns it on, for this one
 * post, and it says what it risks right next to the box.
 */
export function IfMissedChoice({
    publishPlain,
    onChange,
    windowMinutes,
}: {
    publishPlain: boolean;
    onChange: (publishPlain: boolean) => void;
    windowMinutes: number;
}) {
    const windowLabel = describeWindow(windowMinutes);
    const hintId = useId();

    return (
        <div className="border-border/70 grid gap-2 border-t pt-4">
            <label className="flex min-h-11 cursor-pointer items-start gap-3 sm:min-h-0">
                <Checkbox
                    className="mt-0.5"
                    checked={publishPlain}
                    onCheckedChange={(checked) => onChange(checked === true)}
                    aria-describedby={hintId}
                />
                <span className="text-sm text-pretty">
                    If no one posts it within {windowLabel}, publish it without
                    the extras.
                </span>
            </label>
            <p
                id={hintId}
                className="text-muted-foreground ms-[30px] text-xs text-pretty"
            >
                {publishPlain
                    ? 'Careful: if someone posts it by hand and forgets to tap “I’ve posted it”, the story goes out twice.'
                    : `If no one confirms within ${windowLabel}, it’s marked as not posted and you can send a new reminder.`}
            </p>
        </div>
    );
}
