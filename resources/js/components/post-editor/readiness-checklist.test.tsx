import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vite-plus/test';
import { ReadinessChecklist } from '@/components/post-editor/readiness-checklist';
import type { ChecklistItem } from '@/components/post-editor/types';

const items: ChecklistItem[] = [
    { key: 'caption', label: 'Every target has a caption', passed: true },
    {
        key: 'schedule',
        label: 'Every target has a local date and time',
        passed: false,
        detail: 'LinkedIn: no time set',
    },
    {
        key: 'format',
        label: 'Formats fit',
        passed: false,
        fixes: [
            {
                action: 'format',
                label: 'Use Reel on Instagram',
                value: 'reel',
                social_account_id: 3,
            },
        ],
    },
    {
        key: 'hashtags',
        label: 'Hashtags suit each channel',
        passed: false,
        soft: true,
    },
];

describe('ReadinessChecklist', () => {
    it('counts only required checks and lists what is left', () => {
        render(<ReadinessChecklist items={items} />);

        expect(screen.getByText('1/3')).toBeInTheDocument();
        expect(
            screen.getByText('Every channel has a date & time'),
        ).toBeInTheDocument();
        expect(screen.getByText('LinkedIn: no time set')).toBeInTheDocument();
        // A soft check warns, labelled for screen readers, without counting against "ready".
        expect(screen.getByText('Worth a look:')).toBeInTheDocument();
        // Passed checks fold away behind a toggle.
        expect(
            screen.queryByText('Every channel has a caption'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /show 1 done/i }),
        ).toBeInTheDocument();
    });

    it('offers one-click fixes and jumps only when the editor can act on them', async () => {
        const onFix = vi.fn();
        const onJump = vi.fn();
        const { rerender } = render(<ReadinessChecklist items={items} />);

        expect(
            screen.queryByRole('button', { name: /use reel/i }),
        ).not.toBeInTheDocument();

        rerender(
            <ReadinessChecklist items={items} onFix={onFix} onJump={onJump} />,
        );
        await userEvent.click(
            screen.getByRole('button', { name: /use reel on instagram/i }),
        );
        expect(onFix).toHaveBeenCalledWith(items[2].fixes![0]);

        await userEvent.click(
            screen.getByRole('button', {
                name: /every channel has a date & time/i,
            }),
        );
        expect(onJump).toHaveBeenCalledWith('schedule');
    });

    it('says so when everything passes', () => {
        render(
            <ReadinessChecklist
                items={items.map((item) => ({ ...item, passed: true }))}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Ready to schedule.' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/every check passes/i)).toBeInTheDocument();
    });
});
