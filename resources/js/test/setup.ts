// Step I-14.6 / M-1.5 — Vitest setup: Testing Library's DOM matchers, cleanup between tests, and
// the browser APIs jsdom doesn't ship that Radix and our hooks touch.
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vite-plus/test';

afterEach(() => cleanup());

if (!window.matchMedia) {
    window.matchMedia = (query: string) =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addListener: () => undefined,
            removeListener: () => undefined,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            dispatchEvent: () => false,
        }) as MediaQueryList;
}

class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}

window.ResizeObserver ??=
    ResizeObserverStub as unknown as typeof ResizeObserver;
// Radix (Select, Popover) and the editor's "jump to" call these; jsdom leaves them out.
for (const [name, stub] of [
    ['scrollIntoView', () => undefined],
    ['hasPointerCapture', () => false],
    ['releasePointerCapture', () => undefined],
] as const) {
    if (!(name in Element.prototype)) {
        Object.defineProperty(Element.prototype, name, { value: stub });
    }
}
