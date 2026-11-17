import { ActionSheet } from '@capacitor/action-sheet';
import type { MediaResult } from '@capacitor/camera';
import { Camera, MediaType, MediaTypeSelection } from '@capacitor/camera';
import { Filesystem } from '@capacitor/filesystem';
import { fetchBlob } from '@/lib/http';

/**
 * Native shell (Capacitor) — camera and gallery for every media file input.
 *
 * Rather than rewiring each upload screen, the shell takes over the click on any
 * `<input type="file">` that accepts images or video: it shows the native "take / record / choose"
 * sheet, turns the result into ordinary `File`s, puts them on the input and fires `change`. The
 * page's own handler and upload endpoint run exactly as they do in a browser. Inputs for anything
 * else (CSV, PDF) keep the system file chooser. Loaded only inside the shell.
 */

export type MediaKinds = { image: boolean; video: boolean };

/**
 * What a file input's `accept` allows, or null when it isn't a media-only input. An input that
 * also takes documents (the post editor: PDFs, decks, zips) is left to the system chooser, which
 * offers the camera and the gallery as well — taking it over would make documents unreachable.
 */
export function mediaKinds(accept: string): MediaKinds | null {
    const types = accept
        .split(',')
        .map((type) => type.trim())
        .filter(Boolean);
    const kinds = {
        image: types.some((type) => type.startsWith('image/')),
        video: types.some((type) => type.startsWith('video/')),
    };
    const mediaOnly = types.every((type) => /^(image|video)\//.test(type));

    return mediaOnly && (kinds.image || kinds.video) ? kinds : null;
}

async function toFile(result: MediaResult): Promise<File> {
    const isVideo = result.type === MediaType.Video;
    const served = await fetchBlob(result.webPath ?? '');
    let blob: Blob | null = served.ok ? served.blob : null;

    // shortcut: base64 through the bridge holds the whole file in memory. Only reached when the
    // WebView can't serve local files to a remote page (iOS); stream it if large videos matter.
    if (!blob && result.uri) {
        const { data } = await Filesystem.readFile({ path: result.uri });
        blob =
            data instanceof Blob
                ? data
                : new Blob([
                      Uint8Array.from(atob(data), (char) => char.charCodeAt(0)),
                  ]);
    }

    if (!blob) {
        throw new Error('Could not read the selected media.');
    }

    const fallbackType = isVideo ? 'video/mp4' : 'image/jpeg';
    const type = blob.type.startsWith(isVideo ? 'video/' : 'image/')
        ? blob.type
        : fallbackType;
    const name =
        (result.uri ?? result.webPath ?? '')
            .split(/[/?#]/)
            .filter(Boolean)
            .pop() ?? `media.${type.split('/')[1]}`;

    return new File([blob], name, { type });
}

/** Ask where the media comes from, then return it as `File`s (empty when cancelled). */
export async function pickNativeMedia(
    kinds: MediaKinds,
    multiple: boolean,
): Promise<File[]> {
    const choices = [
        ...(kinds.image ? [{ title: 'Take photo', run: 'photo' }] : []),
        ...(kinds.video ? [{ title: 'Record video', run: 'video' }] : []),
        { title: 'Choose from library', run: 'library' },
    ];
    const { index } = await ActionSheet.showActions({
        title: 'Add media',
        options: [...choices, { title: 'Cancel', style: 'CANCEL' as never }],
    });
    const choice = choices[index]?.run;

    try {
        if (choice === 'photo') {
            return [await toFile(await Camera.takePhoto({ quality: 90 }))];
        }

        if (choice === 'video') {
            return [await toFile(await Camera.recordVideo({}))];
        }

        if (choice === 'library') {
            const { results } = await Camera.chooseFromGallery({
                mediaType:
                    kinds.image && kinds.video
                        ? MediaTypeSelection.All
                        : kinds.video
                          ? MediaTypeSelection.Video
                          : MediaTypeSelection.Photo,
                allowMultipleSelection: multiple,
            });

            return Promise.all(results.map(toFile));
        }
    } catch {
        // The user backed out of the camera / picker, or declined the permission.
    }

    return [];
}

/** Take over media file inputs for the lifetime of the page. */
export function installNativeFilePicker(): void {
    document.addEventListener(
        'click',
        (event) => {
            const input = event.target;

            if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
                return;
            }

            const kinds = mediaKinds(input.accept);

            if (!kinds) {
                return;
            }

            event.preventDefault();

            void pickNativeMedia(kinds, input.multiple).then((files) => {
                if (files.length === 0) {
                    return;
                }

                const transfer = new DataTransfer();
                files.forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
        true,
    );
}
