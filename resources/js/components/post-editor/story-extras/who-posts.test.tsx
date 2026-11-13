import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import {
    aLink,
    aPoll,
    Harness,
} from '@/components/post-editor/story-extras/test-harness';

describe('StoryExtrasPanel: who posts it', () => {
    it('defaults who posts it from the client, storing only a different choice', async () => {
        const onRecipient = vi.fn();
        render(<Harness extras={aLink} onRecipient={onRecipient} />);

        const team = screen.getByRole('radio', { name: 'Our team' });
        const client = screen.getByRole('radio', { name: 'Acme' });

        expect(team).toBeChecked();
        expect(client).not.toBeChecked();

        await userEvent.click(client);

        expect(onRecipient).toHaveBeenLastCalledWith('client');
        expect(client).toBeChecked();
        expect(
            screen.getByText(/we.ll send Acme a link to post it themselves/i),
        ).toBeInTheDocument();

        await userEvent.click(team);

        expect(onRecipient).toHaveBeenLastCalledWith(null);
        expect(team).toBeChecked();

        // Tapping the selected one again never leaves nothing selected.
        await userEvent.click(team);

        expect(onRecipient).toHaveBeenCalledTimes(2);
        expect(team).toBeChecked();
        expect(
            screen.getByRole('radiogroup', { name: 'Who posts it' }),
        ).toBeInTheDocument();
    });

    it('follows a client default, and stores the team as the different choice', async () => {
        const onRecipient = vi.fn();
        render(
            <Harness
                extras={aLink}
                options={{ default_recipient: 'client' }}
                onRecipient={onRecipient}
            />,
        );

        expect(screen.getByRole('radio', { name: 'Acme' })).toBeChecked();

        await userEvent.click(screen.getByRole('radio', { name: 'Our team' }));
        expect(onRecipient).toHaveBeenLastCalledWith('team');

        await userEvent.click(screen.getByRole('radio', { name: 'Acme' }));
        expect(onRecipient).toHaveBeenLastCalledWith(null);
    });

    it('warns when the client posts it but has no contact', () => {
        render(
            <Harness
                extras={aLink}
                recipient="client"
                options={{ client_can_be_notified: false }}
            />,
        );

        expect(
            screen.getByText(
                /Acme has no contact to send it to yet, so your team will be asked instead\./,
            ),
        ).toBeInTheDocument();
    });

    it('names the assignee, then the creator, then everyone on the team', () => {
        const { unmount } = render(<Harness extras={aLink} assigneeId="5" />);
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(
            screen.getByText(/gets the reminder when it.s due\./),
        ).toBeInTheDocument();
        unmount();

        const creator = render(<Harness extras={aLink} />);
        expect(screen.getByText('Bob Marsh')).toBeInTheDocument();
        creator.unmount();

        // An assignee who can't be the poster (a client-side role) falls back to the creator too.
        const outsider = render(<Harness extras={aLink} assigneeId="99" />);
        expect(screen.getByText('Bob Marsh')).toBeInTheDocument();
        outsider.unmount();

        render(<Harness extras={aLink} options={{ creator_id: null }} />);
        expect(
            screen.getByText(
                'No one is assigned, so everyone on the team gets the reminder.',
            ),
        ).toBeInTheDocument();
    });

    it('hides the picker where there is no client to choose', () => {
        render(
            <Harness
                extras={aLink}
                recipient="client"
                assigneeId="5"
                options={{ can_choose_recipient: false }}
            />,
        );

        expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument();
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
        // A stale "client" choice is ignored: the team posts it.
        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
    });

    it('toggles publishing it plain when missed, with a caution when on', async () => {
        const onExtras = vi.fn();
        render(<Harness extras={aPoll} onExtras={onExtras} />);
        const box = screen.getByRole('checkbox', {
            name: /if no one posts it within 2 hours, publish it without the extras\./i,
        });

        expect(box).not.toBeChecked();
        expect(screen.queryByText(/^Careful:/)).not.toBeInTheDocument();
        expect(
            screen.getByText(/^If no one confirms within 2 hours/),
        ).toBeInTheDocument();
        expect(box).toHaveAccessibleDescription(
            /^If no one confirms within 2 hours/,
        );

        await userEvent.click(box);

        expect(onExtras).toHaveBeenLastCalledWith({
            ...aPoll,
            if_missed: 'publish_plain',
        });
        expect(box).toBeChecked();
        expect(
            screen.getByText(/^Careful: .*the story goes out twice\.$/),
        ).toBeInTheDocument();

        await userEvent.click(box);

        expect(onExtras).toHaveBeenLastCalledWith({
            ...aPoll,
            if_missed: 'fail',
        });
        expect(box).not.toBeChecked();
        expect(screen.queryByText(/^Careful:/)).not.toBeInTheDocument();
    });
});
