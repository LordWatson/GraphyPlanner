/**
 * Story extras on the frontend (Step S-1.4): the things Instagram only lets a person add to a
 * Story from its own app — a link sticker, mentions, a hashtag, a location, a poll.
 *
 * Mirrors `App\Support\Publishing\StoryExtras`. An extra is "added" when its key is present, even
 * if it isn't filled in yet: a channel with any extra added is posted by hand, and the checklist
 * (`storyExtrasProblems`, the mirror of the server's `problems()`) is what stops an unfinished one
 * being scheduled. The server re-checks everything on save; this is only live feedback.
 */

export type StoryExtras = {
    link?: { url: string; text: string };
    /** Handles without the `@`. */
    mentions?: string[];
    /** One tag without the `#`. */
    hashtag?: string;
    location?: string;
    poll?: { question: string; options: string[] };
    placement_note?: string;
    /** Step S-6.2: what happens when nobody posts it inside the window. */
    if_missed?: 'fail' | 'publish_plain';
};

export type StoryExtraKey =
    | 'link'
    | 'mentions'
    | 'hashtag'
    | 'location'
    | 'poll';

/** Who posts a Story by hand. `null` on a channel means "the client's default". */
export type StoryHandoffRecipient = 'team' | 'client';

export const STORY_EXTRA_KEYS: StoryExtraKey[] = [
    'link',
    'mentions',
    'hashtag',
    'location',
    'poll',
];

export const STORY_EXTRA_LABELS: Record<StoryExtraKey, string> = {
    link: 'Link',
    mentions: 'Mention',
    hashtag: 'Hashtag',
    location: 'Location',
    poll: 'Poll',
};

/** The limits in `StoryExtras` (PHP). Keep the two in step. */
export const STORY_LIMITS = {
    linkText: 30,
    mentions: 5,
    handle: 30,
    hashtag: 100,
    location: 80,
    pollQuestion: 80,
    pollOption: 25,
    minPollOptions: 2,
    maxPollOptions: 4,
    placementNote: 280,
} as const;

const HANDLE = /^[A-Za-z0-9._]{1,30}$/;

/** The server's stored shape (or anything else) → the editor's shape. Null when there is none. */
export function parseStoryExtras(value: unknown): StoryExtras | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const raw = value as Record<string, unknown>;
    const text = (input: unknown) => (typeof input === 'string' ? input : '');
    const extras: StoryExtras = {};

    if (raw.link && typeof raw.link === 'object') {
        const link = raw.link as Record<string, unknown>;
        extras.link = { url: text(link.url), text: text(link.text) };
    }

    if (Array.isArray(raw.mentions)) {
        extras.mentions = raw.mentions
            .filter((handle): handle is string => typeof handle === 'string')
            .map((handle) => handle.replace(/^@/, ''));
    }

    if ('hashtag' in raw) {
        extras.hashtag = text(raw.hashtag).replace(/^#/, '');
    }

    if ('location' in raw) {
        extras.location = text(raw.location);
    }

    if (raw.poll && typeof raw.poll === 'object') {
        const poll = raw.poll as Record<string, unknown>;
        extras.poll = {
            question: text(poll.question),
            options: Array.isArray(poll.options)
                ? poll.options.filter(
                      (option): option is string => typeof option === 'string',
                  )
                : [],
        };
    }

    if (text(raw.placement_note) !== '') {
        extras.placement_note = text(raw.placement_note);
    }

    if (raw.if_missed === 'publish_plain') {
        extras.if_missed = 'publish_plain';
    }

    return Object.keys(extras).length > 0 ? extras : null;
}

/** Whether anything has been added. A placement note alone is not an extra. */
export function hasStoryExtras(
    extras: StoryExtras | null | undefined,
): boolean {
    return (
        !!extras && STORY_EXTRA_KEYS.some((key) => extras[key] !== undefined)
    );
}

/** A fresh, empty extra of each kind — what "Add" puts in. */
export function emptyStoryExtra(key: StoryExtraKey): Partial<StoryExtras> {
    switch (key) {
        case 'link':
            return { link: { url: '', text: '' } };
        case 'mentions':
            return { mentions: [] };
        case 'hashtag':
            return { hashtag: '' };
        case 'location':
            return { location: '' };
        case 'poll':
            return { poll: { question: '', options: ['', ''] } };
    }
}

/**
 * Drop one extra. Removing the last one also drops the note and the "if missed" choice: they only
 * mean something on a Story that is posted by hand.
 */
export function withoutStoryExtra(
    extras: StoryExtras,
    key: StoryExtraKey,
): StoryExtras | null {
    const next = { ...extras };
    delete next[key];

    return hasStoryExtras(next) ? next : null;
}

export function isHttpsUrl(url: string): boolean {
    if (!/^https:\/\//i.test(url)) {
        return false;
    }

    try {
        return new URL(url).hostname.includes('.');
    } catch {
        return false;
    }
}

/** "@Acme.Co, bob" → ["Acme.Co", "bob"]: split on spaces and commas, no `@`, no repeats. */
export function parseHandles(text: string): string[] {
    return [
        ...new Set(
            text
                .split(/[\s,]+/)
                .map((handle) => handle.replace(/^@+/, '').trim())
                .filter((handle) => handle !== ''),
        ),
    ];
}

/** What still has to be filled in, in the server's words. Empty when the extras are complete. */
export function storyExtrasProblems(
    extras: StoryExtras | null | undefined,
): string[] {
    if (!extras) {
        return [];
    }

    const problems: string[] = [];

    if (extras.link) {
        const url = extras.link.url.trim();

        if (url === '') {
            problems.push('the link sticker needs a link.');
        } else if (!isHttpsUrl(url)) {
            problems.push(
                'the link must be a full address starting with https://.',
            );
        }
    }

    if (extras.mentions) {
        if (extras.mentions.length === 0) {
            problems.push('the mention needs a handle.');
        } else if (extras.mentions.some((handle) => !HANDLE.test(handle))) {
            problems.push('a mention isn’t a valid Instagram handle.');
        }
    }

    if (extras.hashtag !== undefined) {
        const tag = extras.hashtag.trim();

        if (tag === '') {
            problems.push('the hashtag sticker needs a tag.');
        } else if (/\s|#/.test(tag)) {
            problems.push('the hashtag must be one word.');
        }
    }

    if (extras.location !== undefined && extras.location.trim() === '') {
        problems.push('the location sticker needs a place.');
    }

    if (extras.poll) {
        if (extras.poll.question.trim() === '') {
            problems.push('the poll needs a question.');
        }

        if (
            extras.poll.options.filter((option) => option.trim() !== '')
                .length < STORY_LIMITS.minPollOptions
        ) {
            problems.push('the poll needs at least two options.');
        }
    }

    return problems;
}

/** Short chips for the preview: "Link: Shop now", "@acme", "Poll". */
export function storyExtrasChips(
    extras: StoryExtras | null | undefined,
): { key: string; label: string }[] {
    if (!extras) {
        return [];
    }

    const chips: { key: string; label: string }[] = [];

    if (extras.link) {
        const text = extras.link.text.trim();
        chips.push({ key: 'link', label: text ? `Link: ${text}` : 'Link' });
    }

    (extras.mentions ?? []).forEach((handle) =>
        chips.push({ key: `mention-${handle}`, label: `@${handle}` }),
    );

    if (extras.mentions && extras.mentions.length === 0) {
        chips.push({ key: 'mention', label: 'Mention' });
    }

    if (extras.hashtag !== undefined) {
        const tag = extras.hashtag.trim();
        chips.push({ key: 'hashtag', label: tag ? `#${tag}` : 'Hashtag' });
    }

    if (extras.location !== undefined) {
        const place = extras.location.trim();
        chips.push({ key: 'location', label: place || 'Location' });
    }

    if (extras.poll) {
        chips.push({ key: 'poll', label: 'Poll' });
    }

    return chips;
}

/**
 * The value of a channel's hidden `story_extras` form field: one JSON string (an empty mentions
 * list has no other form encoding), or '' for none. Keys are only present for extras that were
 * added, which is how the server tells "added but blank" from "not added".
 */
export function serializeStoryExtras(
    extras: StoryExtras | null | undefined,
): string {
    if (!extras || !hasStoryExtras(extras)) {
        return '';
    }

    const payload: Record<string, unknown> = {};

    if (extras.link) {
        payload.link = {
            url: extras.link.url.trim(),
            text: extras.link.text.trim(),
        };
    }

    if (extras.mentions) {
        payload.mentions = extras.mentions;
    }

    if (extras.hashtag !== undefined) {
        payload.hashtag = extras.hashtag.trim().replace(/^#/, '');
    }

    if (extras.location !== undefined) {
        payload.location = extras.location.trim();
    }

    if (extras.poll) {
        payload.poll = {
            question: extras.poll.question.trim(),
            options: extras.poll.options
                .map((option) => option.trim())
                .filter((option) => option !== ''),
        };
    }

    if (extras.placement_note?.trim()) {
        payload.placement_note = extras.placement_note.trim();
    }

    if (extras.if_missed === 'publish_plain') {
        payload.if_missed = 'publish_plain';
    }

    return JSON.stringify(payload);
}

/** "2 hours", "90 minutes", "1 hour" — the window, in words. */
export function describeWindow(minutes: number): string {
    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return hours === 1 ? '1 hour' : `${hours} hours`;
    }

    return `${minutes} minutes`;
}
