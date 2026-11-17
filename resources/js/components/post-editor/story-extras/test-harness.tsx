import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { StoryExtrasPanel } from '@/components/post-editor/story-extras-panel';
import type { StoryHandoffOptions } from '@/components/post-editor/types';
import type { StoryExtras, StoryHandoffRecipient } from '@/lib/story-extras';

/** Shared by the Story extras panel's test files (each stays under the 300-line guard). */
export const baseOptions: StoryHandoffOptions = {
    default_recipient: 'team',
    can_choose_recipient: true,
    client_can_be_notified: true,
    posters: [
        { id: 5, name: 'Ada Lovelace' },
        { id: 6, name: 'Bob Marsh' },
    ],
    creator_id: 6,
    window_minutes: 120,
};

type HarnessProps = {
    extras?: StoryExtras | null;
    recipient?: StoryHandoffRecipient | null;
    options?: Partial<StoryHandoffOptions>;
    postLink?: string;
    assigneeId?: string;
    onExtras?: (extras: StoryExtras | null) => void;
    onRecipient?: (recipient: StoryHandoffRecipient | null) => void;
};

/** The panel is controlled; this holds its state the way the editor does. */
export function Harness({
    extras: initialExtras = null,
    recipient: initialRecipient = null,
    options,
    postLink = '',
    assigneeId = '',
    onExtras,
    onRecipient,
}: HarnessProps) {
    const [extras, setExtras] = useState(initialExtras);
    const [recipient, setRecipient] = useState(initialRecipient);

    return (
        <StoryExtrasPanel
            id="story"
            extras={extras}
            recipient={recipient}
            onExtrasChange={(next) => {
                onExtras?.(next);
                setExtras(next);
            }}
            onRecipientChange={(next) => {
                onRecipient?.(next);
                setRecipient(next);
            }}
            postLink={postLink}
            options={{ ...baseOptions, ...options }}
            assigneeId={assigneeId}
            clientName="Acme"
        />
    );
}

export const aLink: StoryExtras = { link: { url: '', text: '' } };
export const aPoll: StoryExtras = { poll: { question: '', options: ['', ''] } };

export async function addExtra(name: string) {
    const user = userEvent.setup();

    await user.click(
        screen.getByRole('button', { name: /add an(other| extra)/i }),
    );
    await user.click(await screen.findByRole('menuitem', { name }));
}
