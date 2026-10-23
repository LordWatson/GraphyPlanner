import { router } from '@inertiajs/react';
import {
    discard,
    finalize,
    refine,
    regenerate,
} from '@/routes/ai-image-variants';
import { requestId } from '@/lib/request-id';

// Re-exported for the Image Studio call sites that import it from here (Step M-7.2 moved the
// implementation to `@/lib/request-id` so the async text jobs can share it).
export { requestId };

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
