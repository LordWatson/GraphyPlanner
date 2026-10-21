import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vite-plus/test';
import { TimeField } from '@/components/time-field';

function Harness({ initial }: { initial: string }) {
    const [value, setValue] = useState(initial);

    return (
        <>
            <TimeField aria-label="Slot" value={value} onChange={setValue} />
            <output>{value}</output>
        </>
    );
}

describe('TimeField', () => {
    it('offers 24 hours and quarter-hour minutes', () => {
        render(<TimeField aria-label="Slot" value="" onChange={() => {}} />);

        const hours = screen.getByLabelText<HTMLSelectElement>('Slot hour');
        const minutes =
            screen.getByLabelText<HTMLSelectElement>('Slot minutes');

        expect(Array.from(hours.options).map((o) => o.value)).toContain('23');
        expect(
            Array.from(minutes.options)
                .map((o) => o.value)
                .filter(Boolean),
        ).toEqual(['00', '15', '30', '45']);
    });

    it('defaults minutes to :00 when only the hour is picked', () => {
        render(<Harness initial="" />);

        fireEvent.change(screen.getByLabelText('Slot hour'), {
            target: { value: '09' },
        });
        expect(screen.getByRole('status')).toHaveTextContent('09:00');
    });

    it('keeps an off-grid stored minute selectable', () => {
        render(
            <TimeField aria-label="Slot" value="09:05" onChange={() => {}} />,
        );

        const minutes =
            screen.getByLabelText<HTMLSelectElement>('Slot minutes');

        expect(Array.from(minutes.options).map((o) => o.value)).toContain('05');
        expect(minutes.value).toBe('05');
    });
});
