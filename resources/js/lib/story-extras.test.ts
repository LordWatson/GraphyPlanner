import { describe, expect, it } from 'vite-plus/test';
import type { StoryExtras } from '@/lib/story-extras';
import {
    describeWindow,
    hasStoryExtras,
    isHttpsUrl,
    parseHandles,
    parseStoryExtras,
    serializeStoryExtras,
    storyExtrasChips,
    storyExtrasProblems,
    withoutStoryExtra,
} from '@/lib/story-extras';

const complete: StoryExtras = {
    link: { url: 'https://acme.test/sale', text: 'Shop now' },
    mentions: ['acme', 'bob.co'],
    hashtag: 'summersale',
    location: 'Leeds',
    poll: { question: 'Which one?', options: ['Red', 'Blue'] },
};

describe('parseStoryExtras', () => {
    it('returns null for nothing, a list or junk', () => {
        for (const value of [
            null,
            undefined,
            '',
            'link',
            7,
            [],
            ['link'],
            {},
        ]) {
            expect(parseStoryExtras(value)).toBeNull();
        }

        expect(
            parseStoryExtras({ unknown: true, if_missed: 'fail' }),
        ).toBeNull();
    });

    it('strips the @ and # and drops values that are not text', () => {
        expect(
            parseStoryExtras({
                mentions: ['@acme', 3, 'bob'],
                hashtag: '#sale',
                poll: { question: 'Q', options: ['A', null, 'B'] },
            }),
        ).toEqual({
            mentions: ['acme', 'bob'],
            hashtag: 'sale',
            poll: { question: 'Q', options: ['A', 'B'] },
        });
    });

    it('counts a present-but-blank key as added', () => {
        const parsed = parseStoryExtras({
            link: {},
            mentions: [],
            hashtag: null,
            location: '',
            poll: { options: 'nope' },
        });

        expect(parsed).toEqual({
            link: { url: '', text: '' },
            mentions: [],
            hashtag: '',
            location: '',
            poll: { question: '', options: [] },
        });
        expect(hasStoryExtras(parsed)).toBe(true);
    });

    it('keeps the note and only the publish_plain choice', () => {
        expect(
            parseStoryExtras({
                location: 'Leeds',
                placement_note: 'Bottom centre',
                if_missed: 'publish_plain',
            }),
        ).toEqual({
            location: 'Leeds',
            placement_note: 'Bottom centre',
            if_missed: 'publish_plain',
        });
        expect(
            parseStoryExtras({ location: 'Leeds', if_missed: 'fail' }),
        ).toEqual({ location: 'Leeds' });
    });
});

describe('hasStoryExtras', () => {
    it('is false for none, and for a placement note on its own', () => {
        expect(hasStoryExtras(null)).toBe(false);
        expect(hasStoryExtras(undefined)).toBe(false);
        expect(hasStoryExtras({})).toBe(false);
        expect(
            hasStoryExtras({
                placement_note: 'Bottom centre',
                if_missed: 'publish_plain',
            }),
        ).toBe(false);
        expect(hasStoryExtras({ hashtag: '' })).toBe(true);
    });
});

describe('withoutStoryExtra', () => {
    it('removes one extra and keeps the rest', () => {
        expect(
            withoutStoryExtra(
                { hashtag: 'a', location: 'b', placement_note: 'n' },
                'hashtag',
            ),
        ).toEqual({ location: 'b', placement_note: 'n' });
    });

    it('returns null with the last extra, dropping the note and if_missed', () => {
        expect(
            withoutStoryExtra(
                {
                    link: { url: 'https://acme.test', text: '' },
                    placement_note: 'Bottom centre',
                    if_missed: 'publish_plain',
                },
                'link',
            ),
        ).toBeNull();
    });
});

describe('storyExtrasProblems', () => {
    it('is empty for none and for complete extras', () => {
        expect(storyExtrasProblems(null)).toEqual([]);
        expect(storyExtrasProblems(undefined)).toEqual([]);
        expect(storyExtrasProblems(complete)).toEqual([]);
    });

    it.each<[string, StoryExtras, string]>([
        [
            'a blank link',
            { link: { url: '  ', text: '' } },
            'the link sticker needs a link.',
        ],
        [
            'a link that is not https',
            { link: { url: 'http://acme.test', text: '' } },
            'the link must be a full address starting with https://.',
        ],
        ['no handle', { mentions: [] }, 'the mention needs a handle.'],
        [
            'a bad handle',
            { mentions: ['acme', 'not valid!'] },
            'a mention isn’t a valid Instagram handle.',
        ],
        [
            'a blank hashtag',
            { hashtag: ' ' },
            'the hashtag sticker needs a tag.',
        ],
        [
            'a hashtag of two words',
            { hashtag: 'summer sale' },
            'the hashtag must be one word.',
        ],
        [
            'a hashtag with a # inside',
            { hashtag: 'summer#sale' },
            'the hashtag must be one word.',
        ],
        [
            'a blank location',
            { location: '  ' },
            'the location sticker needs a place.',
        ],
        [
            'a poll with no question',
            { poll: { question: '', options: ['A', 'B'] } },
            'the poll needs a question.',
        ],
        [
            'a poll with one real option',
            { poll: { question: 'Q', options: ['A', '  '] } },
            'the poll needs at least two options.',
        ],
    ])('reports %s', (_name, extras, problem) => {
        expect(storyExtrasProblems(extras)).toEqual([problem]);
    });

    it('reports both poll problems together', () => {
        expect(
            storyExtrasProblems({ poll: { question: '', options: ['', ''] } }),
        ).toEqual([
            'the poll needs a question.',
            'the poll needs at least two options.',
        ]);
    });
});

describe('storyExtrasChips', () => {
    it('is empty for none', () => {
        expect(storyExtrasChips(null)).toEqual([]);
    });

    it('labels filled extras with their values', () => {
        expect(storyExtrasChips(complete).map((chip) => chip.label)).toEqual([
            'Link: Shop now',
            '@acme',
            '@bob.co',
            '#summersale',
            'Leeds',
            'Poll',
        ]);
    });

    it('falls back to the plain name for an extra that is still blank', () => {
        expect(
            storyExtrasChips({
                link: { url: '', text: '' },
                mentions: [],
                hashtag: '',
                location: '',
            }).map((chip) => chip.label),
        ).toEqual(['Link', 'Mention', 'Hashtag', 'Location']);
    });
});

describe('serializeStoryExtras', () => {
    it('is an empty string for none, or for a note with no extra', () => {
        expect(serializeStoryExtras(null)).toBe('');
        expect(serializeStoryExtras(undefined)).toBe('');
        expect(serializeStoryExtras({})).toBe('');
        expect(serializeStoryExtras({ placement_note: 'Bottom centre' })).toBe(
            '',
        );
    });

    it('is JSON holding only the keys that were added', () => {
        expect(JSON.parse(serializeStoryExtras({ mentions: [] }))).toEqual({
            mentions: [],
        });
        expect(
            JSON.parse(
                serializeStoryExtras({ hashtag: '', if_missed: 'fail' }),
            ),
        ).toEqual({ hashtag: '' });
    });

    it('trims, drops blank poll options and keeps the note and choice', () => {
        expect(
            JSON.parse(
                serializeStoryExtras({
                    link: { url: ' https://acme.test ', text: ' Shop ' },
                    hashtag: ' #sale ',
                    location: ' Leeds ',
                    poll: { question: ' Q ', options: [' A ', '', '  ', 'B'] },
                    placement_note: ' Bottom centre ',
                    if_missed: 'publish_plain',
                }),
            ),
        ).toEqual({
            link: { url: 'https://acme.test', text: 'Shop' },
            hashtag: 'sale',
            location: 'Leeds',
            poll: { question: 'Q', options: ['A', 'B'] },
            placement_note: 'Bottom centre',
            if_missed: 'publish_plain',
        });
    });
});

describe('parseHandles', () => {
    it('splits on spaces and commas, drops the @ and repeats', () => {
        expect(parseHandles('@Acme.Co, bob  @@c bob')).toEqual([
            'Acme.Co',
            'bob',
            'c',
        ]);
        expect(parseHandles('  ,  ')).toEqual([]);
    });
});

describe('isHttpsUrl', () => {
    it('accepts only a full https address', () => {
        expect(isHttpsUrl('https://acme.test/sale')).toBe(true);
        expect(isHttpsUrl('HTTPS://acme.test')).toBe(true);
        expect(isHttpsUrl('http://acme.test')).toBe(false);
        expect(isHttpsUrl('acme.test')).toBe(false);
        expect(isHttpsUrl('https://localhost')).toBe(false);
        expect(isHttpsUrl('https://')).toBe(false);
        expect(isHttpsUrl('')).toBe(false);
    });
});

describe('describeWindow', () => {
    it('says the window in words', () => {
        expect(describeWindow(60)).toBe('1 hour');
        expect(describeWindow(120)).toBe('2 hours');
        expect(describeWindow(90)).toBe('90 minutes');
    });
});
