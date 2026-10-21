import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { DateTimePicker } from '@/components/date-time-picker';
import type { LocalSlot } from '@/lib/timezones';
import * as timezones from '@/lib/timezones';

function Harness({
    initial,
    timeZone,
}: {
    initial: LocalSlot;
    timeZone?: string;
}) {
    const [value, setValue] = useState(initial);

    return (
        <>
            <DateTimePicker
                label="@harbour on LinkedIn"
                value={value}
                onChange={setValue}
                timeZone={timeZone}
            />
            <output>{`${value.date} ${value.time}`}</output>
        </>
    );
}

describe('DateTimePicker', () => {
    it('labels the clock it is on and shows the viewer the same moment on theirs', () => {
        vi.spyOn(timezones, 'viewerTimeZone').mockReturnValue('Asia/Dubai');

        render(
            <Harness
                initial={{ date: '2026-10-20', time: '09:00' }}
                timeZone="Europe/London"
            />,
        );

        expect(screen.getByText('London time')).toBeInTheDocument();
        // 09:00 BST is 12:00 in Dubai.
        expect(
            screen.getByText(/Your time: Tue 20 Oct, 12:00, Dubai/),
        ).toBeInTheDocument();
    });

    it('hides the viewer line when they share the account’s clock', () => {
        vi.spyOn(timezones, 'viewerTimeZone').mockReturnValue('Europe/London');

        render(
            <Harness
                initial={{ date: '2026-10-20', time: '09:00' }}
                timeZone="Europe/London"
            />,
        );

        expect(screen.queryByText(/Your time/)).not.toBeInTheDocument();
    });

    it('keeps the value a local date and time pair', async () => {
        render(<Harness initial={{ date: '2026-10-20', time: '' }} />);

        fireEvent.change(
            screen.getByLabelText('Time for @harbour on LinkedIn hour'),
            { target: { value: '14' } },
        );
        fireEvent.change(
            screen.getByLabelText('Time for @harbour on LinkedIn minutes'),
            { target: { value: '30' } },
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '2026-10-20 14:30',
        );

        await userEvent.click(
            screen.getByRole('button', { name: /date for @harbour/i }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: /October 22nd, 2026/ }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '2026-10-22 14:30',
        );
    });
});
