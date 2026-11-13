import { useEffect, useState } from 'react';
import type { HandoffMedia } from '@/components/story-handoff/types';
import { fetchBlob } from '@/lib/http';
import { toShareFile } from '@/lib/story-share';

export type HandoffMediaState = {
    /** `loading` while the file downloads, `ready` with a `File`, `unavailable` otherwise. */
    status: 'loading' | 'ready' | 'unavailable';
    /** 0–1 while loading, when the server says how big the file is. */
    progress: number | null;
    /** The media, ready for the share sheet. */
    file: File | null;
    /** What to show: an object URL of the downloaded file, else the media route itself. */
    previewUrl: string | null;
};

/**
 * Step S-4.3 — download the Story's media as soon as the page opens, so a `File` is already in
 * hand when the poster taps Share (a download inside the tap would burn the gesture the share
 * sheet needs). A file too big to hold in memory is never downloaded: the page shows it straight
 * from the media route and leads with "Save media" instead.
 */
export function useHandoffMedia(
    media: HandoffMedia | null,
    enabled: boolean,
): HandoffMediaState {
    const wanted = Boolean(media && media.shareable && enabled);
    const [state, setState] = useState<HandoffMediaState>(() => ({
        status: wanted ? 'loading' : 'unavailable',
        progress: null,
        file: null,
        previewUrl: wanted ? null : (media?.url ?? null),
    }));

    const url = media?.url;
    const filename = media?.filename ?? 'alima-story';
    const mimeType = media?.mime_type ?? '';

    useEffect(() => {
        if (!wanted || !url) {
            return;
        }

        const controller = new AbortController();
        let objectUrl: string | null = null;

        void fetchBlob(url, {
            signal: controller.signal,
            onProgress: (progress) =>
                setState((current) =>
                    current.status === 'loading'
                        ? { ...current, progress }
                        : current,
                ),
        }).then((result) => {
            if (controller.signal.aborted) {
                return;
            }

            if (!result.ok) {
                // Still show it and still let them save it; only the share sheet is off.
                setState({
                    status: 'unavailable',
                    progress: null,
                    file: null,
                    previewUrl: url,
                });

                return;
            }

            objectUrl = URL.createObjectURL(result.blob);
            setState({
                status: 'ready',
                progress: 1,
                file: toShareFile(result.blob, filename, mimeType),
                previewUrl: objectUrl,
            });
        });

        return () => {
            controller.abort();

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [wanted, url, filename, mimeType]);

    return state;
}
