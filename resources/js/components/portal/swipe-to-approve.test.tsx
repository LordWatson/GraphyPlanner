import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import {
    decideFromOffset,
    SwipeToApprove,
} from '@/components/portal/swipe-to-approve';

/**
 * Step M-6.6 (I-8.6) — swipe-to-approve. The gesture→decision mapping is a pure function, so it is
 * tested directly (reliably, without simulating a real pointer drag), and the control renders.
 */
describe('decideFromOffset', () => {
    it('approves past the right threshold, requests changes past the left, nothing in between', () => {
        expect(decideFromOffset(100, 80)).toBe('approve');
        expect(decideFromOffset(80, 80)).toBe('approve');
        expect(decideFromOffset(-100, 80)).toBe('changes');
        expect(decideFromOffset(-80, 80)).toBe('changes');
        expect(decideFromOffset(0, 80)).toBeNull();
        expect(decideFromOffset(40, 80)).toBeNull();
        expect(decideFromOffset(-40, 80)).toBeNull();
    });
});

describe('SwipeToApprove', () => {
    it('renders an accessible swipe control with both decisions in its label', () => {
        render(
            <SwipeToApprove onApprove={vi.fn()} onRequestChanges={vi.fn()} />,
        );

        expect(
            screen.getByLabelText(
                /swipe right to approve, left to request changes/i,
            ),
        ).toBeInTheDocument();
    });
});
