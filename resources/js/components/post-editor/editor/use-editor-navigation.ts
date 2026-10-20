import { useRef, useState } from 'react';
import type { ComposerScope } from '@/components/post-editor/composer';
import type { EditorSection } from '@/components/post-editor/editor/editor-state';
import { cn } from '@/lib/utils';

/**
 * Step I-4.6 / M-1.6 — moving around the editor: the checklist's "jump to the field that fixes
 * this" (scroll + a brief ring), the folded settings panel, and the one channel context the
 * composer edits and the preview shows.
 */
export function useEditorNavigation(selectedIds: number[]) {
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [flashed, setFlashed] = useState<EditorSection | null>(null);
    const [scope, setScope] = useState<ComposerScope>('all');
    const [previewId, setPreviewId] = useState<number | null>(null);
    const captionRef = useRef<HTMLTextAreaElement>(null);
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

    const jumpTo = (section: EditorSection) => {
        if (section === 'settings') {
            setSettingsOpen(true);
        }

        requestAnimationFrame(() => {
            const reduce = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;
            sectionRefs.current[section]?.scrollIntoView({
                behavior: reduce ? 'auto' : 'smooth',
                block: 'start',
            });

            if (section === 'compose') {
                captionRef.current?.focus({ preventScroll: true });
            }

            setFlashed(section);
            window.setTimeout(() => setFlashed(null), 1400);
        });
    };

    const flash = (section: EditorSection) =>
        cn(
            'transition-shadow duration-500',
            flashed === section && 'ring-ring/50 ring-[3px]',
        );

    return {
        settingsOpen,
        setSettingsOpen,
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
