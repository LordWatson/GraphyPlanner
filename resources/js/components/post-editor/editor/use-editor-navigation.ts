import { useRef, useState } from 'react';
import type { ComposerScope } from '@/components/post-editor/composer';
import type { EditorSection } from '@/components/post-editor/editor/editor-state';
import { cn } from '@/lib/utils';

/**
 * The editor's steps (the preview lives in the right rail, not a step). The first four build the
 * post; `review` is the final "Review & send" consolidation step. The readiness checklist's
 * jump targets (`EditorSection`) only ever point at the first four, never `review`.
 */
export type EditorTab =
    | 'compose'
    | 'channels'
    | 'media'
    | 'settings'
    | 'review';

const TAB_IDS: EditorTab[] = [
    'compose',
    'channels',
    'media',
    'settings',
    'review',
];

function isEditorTab(value: unknown): value is EditorTab {
    return typeof value === 'string' && (TAB_IDS as string[]).includes(value);
}

/**
 * A successful save remounts the whole page (Inertia drops the component state when there are no
 * errors), which would otherwise snap the editor back to the first step. Remembering the open step
 * per post in `sessionStorage` keeps the user on the step they were working on after saving.
 */
function readStoredTab(key: string | undefined): EditorTab | null {
    if (!key) {
        return null;
    }

    try {
        const stored = window.sessionStorage.getItem(key);

        return isEditorTab(stored) ? stored : null;
    } catch {
        return null;
    }
}

function writeStoredTab(key: string | undefined, tab: EditorTab) {
    if (!key) {
        return;
    }

    try {
        window.sessionStorage.setItem(key, tab);
    } catch {
        // sessionStorage can be unavailable (private mode); the step simply won't persist.
    }
}

/**
 * Step I-4.6 / M-1.6 — moving around the editor. The Write / Where & when / Media / Settings
 * panels are tabs: one shows at a time while the others stay mounted (so every field still
 * submits). The checklist's "jump to the field that fixes this" switches to that panel's tab,
 * brings it into view and flashes it; media framing jumps to the preview in the right rail.
 * `scope`/`previewId` keep the composer and preview on the one channel being customized.
 *
 * `persistKey` (when given) remembers the open step across a save-triggered remount — see
 * `readStoredTab`.
 */
export function useEditorNavigation(
    selectedIds: number[],
    initialTab: EditorTab = 'compose',
    persistKey?: string,
) {
    const [activeTab, setActiveTabState] = useState<EditorTab>(
        () => readStoredTab(persistKey) ?? initialTab,
    );

    // Every step change is mirrored to sessionStorage so it survives the save remount.
    const setActiveTab = (tab: EditorTab) => {
        setActiveTabState(tab);
        writeStoredTab(persistKey, tab);
    };
    const [flashed, setFlashed] = useState<EditorSection | null>(null);
    const [scope, setScope] = useState<ComposerScope>('all');
    const [previewId, setPreviewId] = useState<number | null>(null);
    const captionRef = useRef<HTMLTextAreaElement>(null);
    const tabsRef = useRef<HTMLDivElement>(null);
    const sectionRefs = useRef<Partial<Record<EditorSection, HTMLElement>>>({});

    const activeScope: ComposerScope =
        scope !== 'all' && selectedIds.includes(scope) ? scope : 'all';

    const changeScope = (next: ComposerScope) => {
        setScope(next);

        if (next !== 'all') {
            setPreviewId(next);
        }
    };

    // While customizing a channel, the preview and composer move together.
    const changePreview = (id: number) => {
        setPreviewId(id);

        if (activeScope !== 'all') {
            setScope(id);
        }
    };

    const sectionRef = (section: EditorSection) => (el: HTMLElement | null) => {
        if (el) {
            sectionRefs.current[section] = el;
        }
    };

    const flashFor = (section: EditorSection) => {
        setFlashed(section);
        window.setTimeout(() => setFlashed(null), 1400);
    };

    const smooth = () =>
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * Move to a step and bring the spine (and the step below it) to the top of the viewport. The
     * action bar is sticky at the bottom, so without this a Back/Next tap at the foot of a long
     * step leaves you scrolled past the new step's start. Reduced motion jumps instead of glides.
     */
    const goToStep = (tab: EditorTab) => {
        setActiveTab(tab);

        requestAnimationFrame(() => {
            tabsRef.current?.scrollIntoView({
                behavior: smooth() ? 'smooth' : 'auto',
                block: 'start',
            });
        });
    };

    const jumpTo = (section: EditorSection) => {
        // Media framing is a right-rail tool, not a tab — scroll to it where it sits.
        if (section === 'preview') {
            requestAnimationFrame(() => {
                sectionRefs.current.preview?.scrollIntoView({
                    behavior: smooth() ? 'smooth' : 'auto',
                    block: 'start',
                });
                flashFor(section);
            });

            return;
        }

        setActiveTab(section);

        requestAnimationFrame(() => {
            tabsRef.current?.scrollIntoView({
                behavior: smooth() ? 'smooth' : 'auto',
                block: 'start',
            });

            if (section === 'compose') {
                captionRef.current?.focus({ preventScroll: true });
            }

            flashFor(section);
        });
    };

    const flash = (section: EditorSection) =>
        cn(
            'transition-shadow duration-500',
            flashed === section && 'ring-ring/50 ring-[3px]',
        );

    return {
        activeTab,
        setTab: setActiveTab,
        goToStep,
        tabsRef,
        activeScope,
        changeScope,
        previewId,
        changePreview,
        captionRef,
        sectionRef,
        jumpTo,
        flash,
    };
}

export type EditorNavigation = ReturnType<typeof useEditorNavigation>;
