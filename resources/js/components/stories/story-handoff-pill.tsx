import { StatusPill } from '@/components/status-pill';

/**
 * Step S-5.1 — a channel's (or post's) assisted-lane state as the backend derives it
 * (`StoryChannelPresenter`): a Story with a link or stickers that a person posts by hand.
 */
export type StoryHandoff = { state: string; label: string };

/** The state as a pill; renders nothing for a channel that publishes itself. */
export function StoryHandoffPill({
    handoff,
    className,
}: {
    handoff: StoryHandoff | null | undefined;
    className?: string;
}) {
    if (!handoff) {
        return null;
    }

    return (
        <StatusPill
            kind="storyHandoff"
            value={handoff.state}
            label={handoff.label}
            className={className}
        />
    );
}
