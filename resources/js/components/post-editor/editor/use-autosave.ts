import { useEffect } from 'react';

/** Statuses that autosave — a post that hasn't gone out and isn't locked. */
const AUTOSAVE_STATUSES = ['idea', 'draft', 'changes_requested'];

export function postAutosaves(canUpdate: boolean, status: string): boolean {
    return canUpdate && AUTOSAVE_STATUSES.includes(status);
}

/**
 * Step M-5.3 — autosave a draft-like post 5 s after the last change. The timer resets on every edit
 * (`stateKey` changes), so it only fires once the user pauses; `submit` goes through the same
 * validated editor form as a manual ⌘S / Save. A published or scheduled post is locked and never
 * autosaves.
 */
export function useAutosave(
    canUpdate: boolean,
    status: string,
    isDirty: boolean,
    stateKey: unknown,
    submit: () => void,
): void {
    const enabled = postAutosaves(canUpdate, status);

    useEffect(() => {
        if (!enabled || !isDirty) {
            return;
        }

        const timer = window.setTimeout(submit, 5000);

        return () => window.clearTimeout(timer);
        // `stateKey` (the editor state) resets the debounce on each change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, isDirty, stateKey]);
}
