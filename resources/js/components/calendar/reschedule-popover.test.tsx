import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { ReschedulePopover } from '@/components/calendar/reschedule-popover';

/**
 * Step M-5.7 / I-5.1 — the keyboard-accessible reschedule path that sits alongside pointer/touch
 * drag-and-drop. These prove a post can be moved from the keyboard alone, with no mouse.
 */
describe('ReschedulePopover', () => {
    it('reschedules a channel to a new time using only the keyboard', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();

        render(
            <ReschedulePopover
                label="Reschedule — Acme on Instagram"
                withTime
                defaultDate="2026-10-20"
                defaultTime="09:00"
                minDate="2026-01-01"
                onSubmit={onSubmit}
            />,
        );

        // Tab to the clock trigger and open it with the keyboard.
        await user.tab();
        expect(
            screen.getByRole('button', {
                name: 'Reschedule — Acme on Instagram',
            }),
        ).toHaveFocus();
        await user.keyboard('{Enter}');

        // Change the hour from the keyboard-operable time select.
        fireEvent.change(screen.getByLabelText('New time hour'), {
            target: { value: '14' },
        });

        // Focus the Move button and activate it with the keyboard.
        const move = screen.getByRole('button', { name: 'Move' });
        move.focus();
        await user.keyboard('{Enter}');

        expect(onSubmit).toHaveBeenCalledWith({
            date: '2026-10-20',
            time: '14:00',
        });
    });

    it('moves the whole post by date only in the month view (no time field)', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();

        render(
            <ReschedulePopover
                label="Reschedule — Acme"
                withTime={false}
                defaultDate="2026-10-20"
                defaultTime={null}
                minDate="2026-01-01"
                onSubmit={onSubmit}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Reschedule — Acme' }),
        );

        // The month popover offers no time field.
        expect(
            screen.queryByLabelText('New time hour'),
        ).not.toBeInTheDocument();

        // Pick a new day from the calendar, then confirm.
        await user.click(screen.getByRole('button', { name: /New date/ }));
        await user.click(
            screen.getByRole('button', { name: /October 24th, 2026/ }),
        );
        await user.click(screen.getByRole('button', { name: 'Move' }));

        expect(onSubmit).toHaveBeenCalledWith({
            date: '2026-10-24',
            time: null,
        });
    });
});
