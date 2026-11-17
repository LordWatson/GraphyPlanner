import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { canShareFile, copyText, shareStory } from '@/lib/story-share';

const file = new File(['x'], 'story.jpg', { type: 'image/jpeg' });
const LINK = 'https://acme.test/sale';

type Stubs = {
    share?: unknown;
    canShare?: unknown;
    clipboard?: unknown;
};

/** Own properties on `navigator`, so deleting them afterwards restores jsdom's own. */
function stubNavigator(stubs: Stubs): void {
    for (const [name, value] of Object.entries(stubs)) {
        Object.defineProperty(navigator, name, {
            configurable: true,
            writable: true,
            value,
        });
    }
}

afterEach(() => {
    for (const name of ['share', 'canShare', 'clipboard']) {
        delete (navigator as unknown as Record<string, unknown>)[name];
    }
});

describe('shareStory', () => {
    it('copies the link, then pings, then shares, with nothing awaited in between', async () => {
        const log: string[] = [];
        let finishCopy: () => void = () => undefined;
        const writeText = vi.fn((_text: string) => {
            log.push('clipboard');

            // Still pending when share() has to be called.
            return new Promise<void>((resolve) => {
                finishCopy = resolve;
            });
        });
        const share = vi.fn((_data: ShareData) => {
            log.push('share');

            return Promise.resolve();
        });
        stubNavigator({
            share,
            canShare: () => true,
            clipboard: { writeText },
        });

        const pending = shareStory({
            file,
            link: LINK,
            onStart: () => log.push('onStart'),
        });

        // Same tick as the tap: no await has run yet, and the copy has not finished.
        expect(log).toEqual(['clipboard', 'onStart', 'share']);
        expect(writeText).toHaveBeenCalledWith(LINK);
        expect(share).toHaveBeenCalledWith({ files: [file] });

        finishCopy();
        await expect(pending).resolves.toEqual({
            outcome: 'shared',
            copied: true,
        });
    });

    it('leaves the clipboard alone when there is no link', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        const onStart = vi.fn();
        stubNavigator({
            share: vi.fn(() => Promise.resolve()),
            canShare: () => true,
            clipboard: { writeText },
        });

        await expect(
            shareStory({ file, link: null, onStart }),
        ).resolves.toEqual({ outcome: 'shared', copied: false });
        expect(writeText).not.toHaveBeenCalled();
        expect(onStart).toHaveBeenCalledTimes(1);
    });

    it('is cancelled when the poster closes the share sheet', async () => {
        stubNavigator({
            share: () =>
                Promise.reject(
                    new DOMException('Share cancelled', 'AbortError'),
                ),
            canShare: () => true,
            clipboard: { writeText: () => Promise.resolve() },
        });

        await expect(shareStory({ file, link: LINK })).resolves.toEqual({
            outcome: 'cancelled',
            copied: true,
        });
    });

    it('is failed on any other error', async () => {
        stubNavigator({
            share: () => Promise.reject(new Error('boom')),
            canShare: () => true,
        });

        await expect(shareStory({ file, link: null })).resolves.toMatchObject({
            outcome: 'failed',
        });
    });

    it('is unsupported when the browser will not share this file', async () => {
        const share = vi.fn(() => Promise.resolve());
        const onStart = vi.fn();
        stubNavigator({
            share,
            canShare: () => false,
            clipboard: { writeText: () => Promise.resolve() },
        });

        // The link is still copied and the server still told: the poster may save it instead.
        await expect(
            shareStory({ file, link: LINK, onStart }),
        ).resolves.toEqual({ outcome: 'unsupported', copied: true });
        expect(share).not.toHaveBeenCalled();
        expect(onStart).toHaveBeenCalledTimes(1);
    });

    it('is unsupported when there is no share at all', async () => {
        stubNavigator({ canShare: () => true });

        await expect(shareStory({ file, link: null })).resolves.toEqual({
            outcome: 'unsupported',
            copied: false,
        });
    });

    it('reports copied: false when the clipboard write fails', async () => {
        stubNavigator({
            share: () => Promise.resolve(),
            canShare: () => true,
            clipboard: { writeText: () => Promise.reject(new Error('denied')) },
        });

        await expect(shareStory({ file, link: LINK })).resolves.toEqual({
            outcome: 'shared',
            copied: false,
        });
    });
});

describe('canShareFile', () => {
    it('is true only when the browser says it can share the file', () => {
        const canShare = vi.fn(() => true);
        stubNavigator({ share: () => Promise.resolve(), canShare });

        expect(canShareFile(file)).toBe(true);
        expect(canShare).toHaveBeenCalledWith({ files: [file] });
    });

    it('is false with no file', () => {
        stubNavigator({ share: () => Promise.resolve(), canShare: () => true });

        expect(canShareFile(null)).toBe(false);
    });

    it('is false with no canShare, or no share', () => {
        stubNavigator({ share: () => Promise.resolve() });
        expect(canShareFile(file)).toBe(false);

        stubNavigator({ share: undefined, canShare: () => true });
        expect(canShareFile(file)).toBe(false);
    });

    it('is false when canShare throws', () => {
        stubNavigator({
            share: () => Promise.resolve(),
            canShare: () => {
                throw new TypeError('nope');
            },
        });

        expect(canShareFile(file)).toBe(false);
    });
});

describe('copyText', () => {
    it('resolves true when the text reaches the clipboard', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        stubNavigator({ clipboard: { writeText } });

        await expect(copyText('hello')).resolves.toBe(true);
        expect(writeText).toHaveBeenCalledWith('hello');
    });

    it('never throws: no clipboard, empty text, a rejection, a sync throw', async () => {
        stubNavigator({ clipboard: undefined });
        await expect(copyText('hello')).resolves.toBe(false);

        const writeText = vi.fn(() => Promise.resolve());
        stubNavigator({ clipboard: { writeText } });
        await expect(copyText('')).resolves.toBe(false);
        expect(writeText).not.toHaveBeenCalled();

        stubNavigator({
            clipboard: { writeText: () => Promise.reject(new Error('denied')) },
        });
        await expect(copyText('hello')).resolves.toBe(false);

        stubNavigator({
            clipboard: {
                writeText: () => {
                    throw new Error('not allowed');
                },
            },
        });
        await expect(copyText('hello')).resolves.toBe(false);
    });
});
