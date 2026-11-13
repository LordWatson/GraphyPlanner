import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import type { StoryQueueRow } from '@/components/stories/story-row';
import { StoryRow, storyPosterLabel } from '@/components/stories/story-row';

const story: StoryQueueRow = {
    handoff_id: 7,
    post_id: 12,
    target_id: 34,
    state: 'ready',
    client_name: 'Acme Coffee',
    platform: 'instagram',
    handle: '@acmecoffee',
    due_at: '2026-11-20T10:00:00+00:00',
    due: { date: '2026-11-20', time: '10:00', timezone: 'UTC' },
    expires_at: '2026-11-21T16:00:00+00:00',
    posted_by: 'team',
    poster_name: 'Rae Lin',
    extras: 'a link and a poll',
    url: '/posts/12/targets/34/story-handoff',
};

/** Steps S-4.4 / S-5.2 — the row every "Stories to post" list is made of. */
describe('StoryRow', () => {
    it('links a ready story to the signed-in open route, with its pill and due time', () => {
        render(<StoryRow story={story} showPoster />);

        // The row names itself from everything in it, not from a label that hides the rest.
        const link = screen.getByRole('link', { name: /@acmecoffee/ });

        expect(link).not.toHaveAttribute('aria-label');
        expect(link).toHaveAccessibleName(
            /Acme Coffee · With a link and a poll · Posting: Rae Lin.*Fri 20 Nov · 10:00.*Ready to post/,
        );

        expect(link).toHaveAttribute(
            'href',
            '/posts/12/targets/34/story-handoff',
        );
        expect(link).toHaveTextContent('Ready to post');
        expect(link).toHaveTextContent('Fri 20 Nov · 10:00');
        expect(link).toHaveTextContent(
            'Acme Coffee · With a link and a poll · Posting: Rae Lin',
        );
        // Never the raw API date.
        expect(link).not.toHaveTextContent('2026-11-20');
    });

    it('shows an upcoming story as due later, without the ready pill', () => {
        render(
            <StoryRow
                story={{
                    ...story,
                    state: 'upcoming',
                    handoff_id: null,
                    due: { date: '2026-11-20', time: '16:30', timezone: 'UTC' },
                }}
            />,
        );

        const link = screen.getByRole('link', {
            name: /@acmecoffee.*Due Fri 20 Nov · 16:30/,
        });

        expect(link).toHaveTextContent('Due Fri 20 Nov · 16:30');
        expect(link).not.toHaveTextContent('Ready to post');
        expect(link).not.toHaveTextContent('Posting:');
    });

    it('names who is posting', () => {
        expect(storyPosterLabel(story)).toBe('Rae Lin');
        expect(storyPosterLabel({ ...story, poster_name: null })).toBe(
            'Everyone on the team',
        );
        expect(
            storyPosterLabel({
                ...story,
                posted_by: 'client',
                poster_name: null,
            }),
        ).toBe('The client');
    });
});
