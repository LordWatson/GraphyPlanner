/**
 * Step S-4.3 — handing a Story to Instagram from the handoff page.
 *
 * The whole "three taps" promise rests on the phone's share sheet taking a file into Instagram's
 * Story editor (`navigator.share({ files })`). Three rules, from the share-sheet spike (S-0.1),
 * that this module exists to keep in one place:
 *
 * 1. **The file is fetched before the tap.** `share()` must run inside the user's gesture, and a
 *    download in between burns the activation. So the page downloads the media when it opens and
 *    this is only ever given a ready `File`.
 * 2. **Clipboard first, share second, in the same handler.** Nothing is awaited between the tap
 *    and `share()`: the clipboard write and the "shared" ping are started, not waited for.
 * 3. **Where sharing a file isn't possible, say so up front** (`canShareFile`), so the page can
 *    lead with "Save media" instead of offering a button that does nothing.
 */

export type ShareOutcome =
    /** The share sheet took it (the poster may still have picked something other than Instagram). */
    | 'shared'
    /** The poster closed the share sheet. Nothing went anywhere. */
    | 'cancelled'
    /** This browser can't share a file. */
    | 'unsupported'
    | 'failed';

type ShareNavigator = Pick<Navigator, 'share'> & {
    canShare?: (data?: ShareData) => boolean;
    clipboard?: Pick<Clipboard, 'writeText'>;
};

function shareNavigator(): ShareNavigator | null {
    return typeof navigator === 'undefined'
        ? null
        : (navigator as unknown as ShareNavigator);
}

/** Whether this browser will hand this file to another app. */
export function canShareFile(file: File | null): boolean {
    const nav = shareNavigator();

    if (!nav || !file || typeof nav.share !== 'function') {
        return false;
    }

    try {
        return nav.canShare?.({ files: [file] }) === true;
    } catch {
        return false;
    }
}

/** Copy text without ever throwing. Resolves to whether it reached the clipboard. */
export function copyText(text: string): Promise<boolean> {
    const clipboard = shareNavigator()?.clipboard;

    if (!clipboard || text === '') {
        return Promise.resolve(false);
    }

    try {
        return clipboard.writeText(text).then(
            () => true,
            () => false,
        );
    } catch {
        return Promise.resolve(false);
    }
}

/**
 * The share tap. Synchronously, in this order: start copying the link, tell the server the poster
 * has started (`onStart` — fire and forget), open the share sheet with the file. Only the share
 * sheet itself is awaited.
 */
export async function shareStory({
    file,
    link,
    onStart,
}: {
    file: File;
    link: string | null;
    onStart?: () => void;
}): Promise<{ outcome: ShareOutcome; copied: boolean }> {
    const nav = shareNavigator();
    const copying = link ? copyText(link) : Promise.resolve(false);

    onStart?.();

    if (!nav || !canShareFile(file)) {
        return { outcome: 'unsupported', copied: await copying };
    }

    let outcome: ShareOutcome;

    try {
        await nav.share({ files: [file] });
        outcome = 'shared';
    } catch (error) {
        outcome =
            error instanceof DOMException && error.name === 'AbortError'
                ? 'cancelled'
                : 'failed';
    }

    return { outcome, copied: await copying };
}

/** A downloaded blob as the `File` the share sheet takes. */
export function toShareFile(blob: Blob, filename: string, type: string): File {
    return new File([blob], filename, { type: type || blob.type });
}
