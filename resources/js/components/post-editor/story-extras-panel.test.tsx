import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import {
    addExtra,
    aLink,
    Harness,
} from '@/components/post-editor/story-extras/test-harness';

describe('StoryExtrasPanel', () => {
    it('starts as auto-publish with nothing shown empty', () => {
        render(<Harness />);

        expect(screen.getByText('Auto-publish')).toBeInTheDocument();
        expect(
            screen.queryByText('Needs posting by hand'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByText('Who posts it')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add an extra' }),
        ).toBeInTheDocument();
    });

    it('flips the pill on the first extra and back when the last one is removed', async () => {
        const onExtras = vi.fn();
        render(<Harness onExtras={onExtras} />);

        await addExtra('Link');

        expect(onExtras).toHaveBeenLastCalledWith({
            link: { url: '', text: '' },
        });
        expect(screen.getByText('Needs posting by hand')).toBeInTheDocument();
        expect(screen.queryByText('Auto-publish')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                /Instagram only allows links and stickers from its own app\./,
            ),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Link')).toHaveValue('');
        // Focus lands in the new extra's first field, not back on the menu button.
        await waitFor(() =>
            expect(screen.getByLabelText('Link')).toHaveFocus(),
        );
        expect(
            screen.getByRole('button', { name: 'Add another' }),
        ).toBeInTheDocument();

        await userEvent.click(
            screen.getByRole('button', { name: 'Remove link' }),
        );

        expect(onExtras).toHaveBeenLastCalledWith(null);
        expect(screen.getByText('Auto-publish')).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add an extra' }),
        ).toHaveFocus();
    });

    it('offers each extra once, and stays by hand while one is left', async () => {
        render(<Harness extras={{ ...aLink, hashtag: 'sale' }} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Add another' }),
        );

        expect(
            (await screen.findAllByRole('menuitem')).map(
                (item) => item.textContent,
            ),
        ).toEqual(['Mention', 'Location', 'Poll']);

        await userEvent.keyboard('{Escape}');
        await userEvent.click(
            screen.getByRole('button', { name: 'Remove link' }),
        );

        expect(screen.getByText('Needs posting by hand')).toBeInTheDocument();
    });

    it('prefills the link from the post, and only offers to when it differs', async () => {
        render(<Harness extras={aLink} postLink="https://acme.test/sale" />);

        await userEvent.click(
            screen.getByRole('button', { name: /use the post.s link/i }),
        );

        expect(screen.getByLabelText('Link')).toHaveValue(
            'https://acme.test/sale',
        );
        expect(
            screen.queryByRole('button', { name: /use the post.s link/i }),
        ).not.toBeInTheDocument();
    });

    it('does not offer the post’s link when the post has none', () => {
        render(<Harness extras={aLink} />);

        expect(
            screen.queryByRole('button', { name: /use the post.s link/i }),
        ).not.toBeInTheDocument();
    });

    it('flags a link that is not https, inline', () => {
        render(<Harness extras={aLink} />);
        const url = screen.getByLabelText('Link');

        expect(url).toHaveAttribute('aria-invalid', 'false');

        fireEvent.change(url, { target: { value: 'http://acme.test' } });

        expect(
            screen.getByText('Enter a full link, starting with https://.'),
        ).toBeInTheDocument();
        expect(url).toHaveAttribute('aria-invalid', 'true');

        fireEvent.change(url, { target: { value: 'https://acme.test' } });

        expect(
            screen.queryByText('Enter a full link, starting with https://.'),
        ).not.toBeInTheDocument();
    });

    it('keeps a poll between two and four options', async () => {
        const onExtras = vi.fn();
        render(<Harness onExtras={onExtras} />);

        await addExtra('Poll');

        const options = () => screen.queryAllByLabelText(/^Poll option \d$/);
        const removes = () =>
            screen.queryAllByRole('button', { name: /^Remove option \d$/ });
        const add = () =>
            screen.queryByRole('button', { name: 'Add an option' });

        expect(options()).toHaveLength(2);
        expect(removes()).toHaveLength(0);

        await userEvent.click(add()!);
        expect(options()).toHaveLength(3);
        expect(removes()).toHaveLength(3);

        await userEvent.click(add()!);
        expect(options()).toHaveLength(4);
        expect(add()).not.toBeInTheDocument();

        fireEvent.change(options()[2], { target: { value: 'Green' } });
        await userEvent.click(
            screen.getByRole('button', { name: 'Remove option 4' }),
        );
        expect(options()[2]).toHaveFocus();
        await userEvent.click(
            screen.getByRole('button', { name: 'Remove option 1' }),
        );

        expect(options()).toHaveLength(2);
        expect(options()[0]).toHaveFocus();
        expect(removes()).toHaveLength(0);
        expect(add()).toBeInTheDocument();
        expect(onExtras).toHaveBeenLastCalledWith({
            poll: { question: '', options: ['', 'Green'] },
        });
    });

    it('still shows two option fields for a saved poll with fewer', () => {
        render(
            <Harness extras={{ poll: { question: 'Q', options: ['A'] } }} />,
        );

        expect(screen.getAllByLabelText(/^Poll option \d$/)).toHaveLength(2);
    });

    it('turns typed mentions into a list of handles', () => {
        const onExtras = vi.fn();
        render(<Harness extras={{ mentions: [] }} onExtras={onExtras} />);
        const field = screen.getByLabelText('Accounts to mention');

        fireEvent.change(field, { target: { value: '@a, b  @c' } });

        expect(onExtras).toHaveBeenLastCalledWith({
            mentions: ['a', 'b', 'c'],
        });
        // What was typed stays as typed.
        expect(field).toHaveValue('@a, b  @c');

        fireEvent.change(field, { target: { value: '@a not!valid' } });

        expect(
            screen.getByText(/@not!valid isn.t a valid handle/),
        ).toBeInTheDocument();
    });
});
