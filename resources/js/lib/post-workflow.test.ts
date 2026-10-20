import { describe, expect, it } from 'vite-plus/test';
import {
    describeTransition,
    primaryTransitionFor,
    stageIndexFor,
} from '@/lib/post-workflow';

describe('post workflow', () => {
    it('recommends the next forward step for each status', () => {
        const all = [
            'draft',
            'internal_review',
            'waiting_client',
            'approved',
            'scheduled',
        ];

        expect(primaryTransitionFor('idea', all, 'client_required')).toBe(
            'draft',
        );
        expect(primaryTransitionFor('draft', all, 'client_required')).toBe(
            'internal_review',
        );
        expect(
            primaryTransitionFor('internal_review', all, 'client_required'),
        ).toBe('waiting_client');
        expect(
            primaryTransitionFor('internal_review', all, 'internal_only'),
        ).toBe('approved');
        expect(primaryTransitionFor('approved', all, 'client_required')).toBe(
            'scheduled',
        );
    });

    it('never recommends a move the server does not allow', () => {
        expect(
            primaryTransitionFor('approved', ['draft'], 'client_required'),
        ).toBeNull();
        expect(
            primaryTransitionFor('published', [], 'client_required'),
        ).toBeNull();
    });

    it('labels moves in plain words', () => {
        expect(
            describeTransition('draft', 'internal_review', 'Harbour').label,
        ).toBe('Send for internal review');
        expect(
            describeTransition(
                'changes_requested',
                'internal_review',
                'Harbour',
            ).label,
        ).toBe('Resubmit for review');
        expect(
            describeTransition('internal_review', 'waiting_client', 'Harbour')
                .description,
        ).toContain('Harbour');
        expect(describeTransition('failed', 'scheduled', 'Harbour').label).toBe(
            'Retry publishing',
        );
    });

    it('maps statuses onto the stage track', () => {
        expect(stageIndexFor('idea')).toBe(stageIndexFor('draft'));
        expect(stageIndexFor('scheduled')).toBeGreaterThan(
            stageIndexFor('approved'),
        );
        expect(stageIndexFor('archived')).toBe(-1);
    });
});
