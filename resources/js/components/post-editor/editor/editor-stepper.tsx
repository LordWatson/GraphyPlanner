import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import {
    editorTabs,
    tabForErrorKey,
} from '@/components/post-editor/editor/editor-steps';
import type { EditorTab } from '@/components/post-editor/editor/use-editor-navigation';
import { cn } from '@/lib/utils';

export { StepSpine } from '@/components/post-editor/editor/step-spine';
export { StepActionBar } from '@/components/post-editor/editor/step-action-bar';

/**
 * After a failed save, bring the first step that has a validation error into view — a hidden
 * step must never swallow an error. Runs once per distinct error set, so it never fights a user
 * who then navigates to a different step while the errors are still showing.
 */
export function ErrorStepSwitcher({
    errors,
}: {
    errors: Record<string, string>;
}) {
    const { nav } = usePostEditorContext();
    const keys = Object.keys(errors);
    const signature = [...keys].sort().join('|');
    const seen = useRef('');

    useEffect(() => {
        if (signature === seen.current) {
            return;
        }

        seen.current = signature;

        if (keys.length === 0) {
            return;
        }

        const order = editorTabs.map((tab) => tab.id);
        const target = [...keys]
            .map(tabForErrorKey)
            .sort((a, b) => order.indexOf(a) - order.indexOf(b))[0];
        nav.goToStep(target);
    });

    return null;
}

/** Wraps one step: shown only when active, mounted always so its fields still submit. */
export function StepPanel({
    tab,
    children,
}: {
    tab: EditorTab;
    children: ReactNode;
}) {
    const { nav } = usePostEditorContext();
    const active = nav.activeTab === tab;

    return (
        <div
            role="tabpanel"
            id={`post-steppanel-${tab}`}
            aria-labelledby={`post-step-${tab}`}
            hidden={!active}
            className={cn(!active && 'hidden')}
        >
            {children}
        </div>
    );
}
