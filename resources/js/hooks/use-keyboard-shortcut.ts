import { useEffect, useRef } from 'react';

export type Shortcut = {
    /** `KeyboardEvent.key`, case-insensitive (e.g. `s`, `Enter`, `?`). */
    key: string;
    /** Requires ⌘ on macOS or Ctrl elsewhere. */
    mod?: boolean;
    /** Fire even while typing in a field (save shortcuts do; `?` doesn't). */
    whileTyping?: boolean;
};

function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

export function matchesShortcut(
    event: KeyboardEvent,
    shortcut: Shortcut,
): boolean {
    const mod = event.metaKey || event.ctrlKey;

    if (Boolean(shortcut.mod) !== mod) {
        return false;
    }

    if (!shortcut.whileTyping && isTyping(event.target)) {
        return false;
    }

    return event.key.toLowerCase() === shortcut.key.toLowerCase();
}

/**
 * Step I-4.6 / M-1.6 — one window-level keyboard shortcut. The handler is read through a ref, so
 * callers can pass an inline function without re-binding the listener every render.
 */
export function useKeyboardShortcut(
    shortcut: Shortcut,
    handler: (event: KeyboardEvent) => void,
    enabled = true,
): void {
    const handlerRef = useRef(handler);
    handlerRef.current = handler;

    const { key, mod, whileTyping } = shortcut;

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                !event.defaultPrevented &&
                matchesShortcut(event, { key, mod, whileTyping })
            ) {
                event.preventDefault();
                handlerRef.current(event);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [key, mod, whileTyping, enabled]);
}
