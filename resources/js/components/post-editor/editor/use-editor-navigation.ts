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

/**
 * Step I-4.6 / M-1.6 — moving around the editor. The Write / Where & when / Media / Settings
 * panels are tabs: one shows at a time while the others stay mounted (so every field still
 * submits). The checklist's "jump to the field that fixes this" switches to that panel's tab,
 * brings it into view and flashes it; media framing jumps to the preview in the right rail.
 * `scope`/`previewId` keep the composer and preview on the one channel being customized.
 */
export function useEditorNavigation(selectedIds: number[]) {
    const [activeTab, setActiveTab] = useState<EditorTab>('compose');
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
