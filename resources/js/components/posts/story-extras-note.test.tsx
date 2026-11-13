import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';
import type { ClientStory } from '@/components/posts/story-extras-note';
import { StoryExtrasNote } from '@/components/posts/story-extras-note';

const story: ClientStory = {
    extras: [
        { key: 'link', label: 'Link', value: 'https://acme.test/sale' },
        { key: 'poll', label: 'Poll', value: 'Which one? Red / Blue' },
    ],
    summary: 'a link and a poll',
    placement_note: 'Link sticker bottom centre.',
    posted_by: 'team',
};

describe('StoryExtrasNote', () => {
    it('renders nothing when no story is posted by hand', () => {
        const { container } = render(
            <StoryExtrasNote
                stories={[
                    { handle: '@acme', story: null },
                    { handle: '@other', story: undefined },
                ]}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('lists the extras and says the story is posted by hand', () => {
        render(
            <StoryExtrasNote
                stories={[
                    { handle: '@acme', story },
                    { handle: '@plain', story: null },
                ]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Story extras' }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('heading')).toHaveLength(1);
        expect(
            screen.getByText(
                /@acme’s story has a link and a poll, so it will be posted by hand at the scheduled time\./i,
            ),
        ).toBeInTheDocument();

        const terms = screen.getAllByRole('term').map((el) => el.textContent);
        const values = screen
            .getAllByRole('definition')
            .map((el) => el.textContent);

        expect(terms).toEqual(['Link', 'Poll']);
        expect(values).toEqual([
            'https://acme.test/sale',
            'Which one? Red / Blue',
        ]);
        expect(
            screen.getByText(/link sticker bottom centre\./i),
        ).toBeInTheDocument();
        // The team posts this one: the client is not told they will get a link.
        expect(
            screen.queryByText(/send you a link to post it yourselves/i),
        ).not.toBeInTheDocument();
    });

    it('tells the client they will get a link only when they post it', () => {
        render(
            <StoryExtrasNote
                stories={[
                    {
                        handle: null,
                        story: {
                            ...story,
                            summary: '',
                            placement_note: null,
                            posted_by: 'client',
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.getByText('We’ll send you a link to post it yourselves.'),
        ).toBeInTheDocument();
        // No summary from the server: it still reads as a sentence.
        expect(
            screen.getByText(/this story has extras, so/i),
        ).toBeInTheDocument();
        expect(screen.queryByText('Placement')).not.toBeInTheDocument();
    });
});
