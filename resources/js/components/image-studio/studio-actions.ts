import { router } from '@inertiajs/react';
import {
    discard,
    finalize,
    refine,
    regenerate,
} from '@/routes/ai-image-variants';

/**
 * A v4 UUID per click, sent as `request_id` so a double click resolves to the same set/variant
 * server-side. `crypto.randomUUID()` needs a secure context (local `.test` hosts are plain http),
 * so fall back to `getRandomValues`, which works everywhere.
 */
export function requestId(): string {
    if (typeof crypto.randomUUID === 'function' && window.isSecureContext) {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

type Callbacks = {
    onError?: (message: string) => void;
    onFinish?: () => void;
    /** Reload the whole page (e.g. after attaching media in the post editor). */
    fullReload?: boolean;
};

function firstError(errors: Record<string, string>): string {
    return (
        errors.imageStudio ??
        Object.values(errors)[0] ??
        'Something went wrong. Try again.'
    );
}

/** Every studio write reloads only the studio's own prop. */
function post(
    url: string,
    data: Record<string, unknown>,
    { onError, onFinish, fullReload = false }: Callbacks,
) {
    router.post(url, data as never, {
        preserveScroll: true,
        preserveState: !fullReload,
        ...(fullReload ? {} : { only: ['imageStudio', 'errors', 'flash'] }),
        onError: (errors) => onError?.(firstError(errors)),
        onFinish: () => onFinish?.(),
    });
}

export const studioActions = {
    post,
    refine: (variantId: number, instruction: string, callbacks: Callbacks) =>
        post(
            refine.url(variantId),
            { instruction, request_id: requestId() },
            callbacks,
        ),
    regenerate: (variantId: number, callbacks: Callbacks) =>
        post(regenerate.url(variantId), { request_id: requestId() }, callbacks),
    finalize: (variantId: number, callbacks: Callbacks) =>
        post(finalize.url(variantId), { request_id: requestId() }, callbacks),
    discard: (variantId: number, discarded: boolean, callbacks: Callbacks) =>
        post(discard.url(variantId), { discarded }, callbacks),
};
