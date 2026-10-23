import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    Circle,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import { checkSection } from '@/components/post-editor/editor/editor-state';
import type { EditorTab } from '@/components/post-editor/editor/use-editor-navigation';
import type { ChecklistItem } from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** The editor's four panels, in the order a post is usually built. */
export const editorTabs: { id: EditorTab; label: string }[] = [
    { id: 'compose', label: 'Write' },
    { id: 'channels', label: 'Where & when' },
    { id: 'media', label: 'Media' },
    { id: 'settings', label: 'Settings' },
];

type TabStatus = 'error' | 'done' | 'todo' | 'attention' | 'neutral';

/** Which panel a server validation error belongs to, so a hidden error can't be missed. */
export function tabForErrorKey(key: string): EditorTab {
    if (key.startsWith('targets')) {
        return 'channels';
    }

    if (
        key.startsWith('asset') ||
        key.startsWith('media') ||
        key === 'cover_asset_id' ||
        key === 'thumb_offset_ms'
    ) {
        return 'media';
    }

    if (
        key === 'campaign_id' ||
        key === 'approval_mode' ||
        key === 'recurrence' ||
        key === 'recycle_after_days' ||
        key === 'is_recyclable' ||
        key.startsWith('location')
    ) {
        return 'settings';
    }

    // master_caption, hashtags, link_url, first_comment and anything unmapped.
    return 'compose';
}

/**
 * A tab's readiness from the live checklist (grouped by `checkSection`): a blocking check still
 * open → "todo"; only a soft warning open → "attention"; all its checks pass → "done"; the panel
 * owns no checks (e.g. Settings with no campaign) → "neutral", no indicator.
 */
export function tabStatus(tab: EditorTab, items: ChecklistItem[]): TabStatus {
    const own = items.filter((item) => checkSection[item.key] === tab);

    if (own.length === 0) {
        return 'neutral';
    }

    if (own.some((item) => !item.soft && !item.passed)) {
        return 'todo';
    }

    if (own.some((item) => item.soft && !item.passed)) {
        return 'attention';
    }

    return 'done';
}

const statusMeta: Record<
    Exclude<TabStatus, 'neutral'>,
    { Icon: typeof Check; className: string; label: string }
> = {
    error: {
        Icon: TriangleAlert,
        className: 'text-destructive',
        label: 'needs fixing',
    },
    done: { Icon: Check, className: 'text-success', label: 'ready' },
    attention: {
        Icon: AlertTriangle,
        className: 'text-warning',
        label: 'worth a look',
    },
    todo: { Icon: Circle, className: 'text-muted-foreground', label: 'to do' },
};

function TabStatusDot({ status }: { status: TabStatus }) {
    if (status === 'neutral') {
        return null;
    }

    const { Icon, className, label } = statusMeta[status];

    return (
        <>
            <Icon className={cn('size-3.5 shrink-0', className)} aria-hidden />
            <span className="sr-only"> — {label}</span>
        </>
    );
}

/** The segmented tab bar: pick a panel, with each panel's readiness shown at a glance. */
export function EditorTabs({
    errors = {},
}: {
    errors?: Record<string, string>;
}) {
    const { editor, nav } = usePostEditorContext();
    const items = editor.view.checklistItems;
    const errorTabs = new Set(Object.keys(errors).map(tabForErrorKey));

    return (
        <div
            ref={nav.tabsRef}
            role="tablist"
            aria-label="Post editor sections"
            className="bg-muted flex scroll-mt-20 gap-1 overflow-x-auto rounded-full border p-1"
        >
            {editorTabs.map((tab, index) => {
                const active = nav.activeTab === tab.id;

                return (
                    <button
                        key={tab.id}
                        type="button"
                        role="tab"
                        id={`post-tab-${tab.id}`}
                        aria-controls={`post-tabpanel-${tab.id}`}
                        aria-selected={active}
                        onClick={() => nav.setTab(tab.id)}
                        className={cn(
                            'focus-visible:ring-ring/50 inline-flex min-w-fit flex-1 items-center justify-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] outline-none focus-visible:ring-[3px]',
                            active
                                ? 'bg-card text-foreground border-input shadow-sm'
                                : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        <span className="font-mono text-xs tabular-nums opacity-70">
                            {String(index + 1).padStart(2, '0')}
                        </span>
                        <span className="truncate">{tab.label}</span>
                        <TabStatusDot
                            status={
                                errorTabs.has(tab.id)
                                    ? 'error'
                                    : tabStatus(tab.id, items)
                            }
                        />
                    </button>
                );
            })}
        </div>
    );
}

/**
 * After a failed save, bring the first panel that has a validation error into view — a hidden
 * tab must never swallow an error. Runs once per distinct error set, so it never fights a user
 * who then navigates to a different tab while the errors are still showing.
 */
export function ErrorTabSwitcher({
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
        nav.setTab(target);
    });

    return null;
}

/** Wraps one panel: shown only when its tab is active, mounted always so its fields still submit. */
export function TabPanel({
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
            id={`post-tabpanel-${tab}`}
            aria-labelledby={`post-tab-${tab}`}
            hidden={!active}
            className={cn(!active && 'hidden')}
        >
            {children}
        </div>
    );
}

/** Back / Next between panels under the active one — a gentle guided path, never blocking. */
export function TabFooter() {
    const { nav } = usePostEditorContext();
    const index = editorTabs.findIndex((tab) => tab.id === nav.activeTab);
    const prev = editorTabs[index - 1];
    const next = editorTabs[index + 1];

    return (
        <div className="flex items-center justify-between gap-3">
            {prev ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => nav.setTab(prev.id)}
                >
                    <ArrowLeft />
                    {prev.label}
                </Button>
            ) : (
                <span />
            )}
            {next ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => nav.setTab(next.id)}
                >
                    Next: {next.label}
                    <ArrowRight />
                </Button>
            ) : (
                <span />
            )}
        </div>
    );
}
