import { AlertTriangle, Check, TriangleAlert } from 'lucide-react';
import { usePostEditorContext } from '@/components/post-editor/editor/editor-context';
import {
    editorSteps,
    reviewStatus,
    tabForErrorKey,
    tabStatus,
    type TabStatus,
} from '@/components/post-editor/editor/editor-steps';
import { cn } from '@/lib/utils';

const statusMeta: Record<
    Exclude<TabStatus, 'neutral' | 'todo'>,
    { Icon: typeof Check; disc: string }
> = {
    error: {
        Icon: TriangleAlert,
        disc: 'border-destructive/50 bg-destructive/10 text-destructive',
    },
    done: {
        Icon: Check,
        disc: 'border-success/40 bg-success/15 text-success',
    },
    attention: {
        Icon: AlertTriangle,
        disc: 'border-warning/45 bg-warning/10 text-warning',
    },
};

/** Spoken status suffix for each step's accessible name (omitted when the step owns no checks). */
const statusLabel: Record<TabStatus, string | null> = {
    error: 'needs fixing',
    done: 'ready',
    attention: 'worth a look',
    todo: 'to do',
    neutral: null,
};

/** The disc at the head of each step: a status icon when it means something, else the number. */
function StepDisc({
    status,
    index,
    active,
}: {
    status: TabStatus;
    index: number;
    active: boolean;
}) {
    if (status === 'neutral' || status === 'todo') {
        return (
            <span
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full border font-mono text-xs tabular-nums transition-colors',
                    active
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-input text-muted-foreground',
                )}
            >
                {String(index + 1).padStart(2, '0')}
            </span>
        );
    }

    const { Icon, disc } = statusMeta[status];

    return (
        <span
            aria-hidden
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-full border transition-colors',
                active && 'ring-primary/40 ring-2',
                disc,
            )}
        >
            <Icon className="size-3.5" />
        </span>
    );
}

/**
 * The progress spine: the one place the post's shape is read at a glance. Each step shows its
 * number, its live readiness, and jumps there on click (free navigation — never blocking).
 */
export function StepSpine({
    errors = {},
}: {
    errors?: Record<string, string>;
}) {
    const { editor, nav } = usePostEditorContext();
    const items = editor.view.checklistItems;
    const errorTabs = new Set(Object.keys(errors).map(tabForErrorKey));

    return (
        <nav
            ref={nav.tabsRef}
            aria-label="Post editor steps"
            className="border-border bg-card scroll-mt-20 rounded-2xl border p-2 shadow-sm"
        >
            <ol
                role="tablist"
                className="flex items-stretch gap-1 overflow-x-auto"
            >
                {editorSteps.map((step, index) => {
                    const active = nav.activeTab === step.id;
                    const status: TabStatus = errorTabs.has(step.id)
                        ? 'error'
                        : step.id === 'review'
                          ? reviewStatus(items)
                          : tabStatus(step.id, items);
                    const last = index === editorSteps.length - 1;
                    const spoken = statusLabel[status];

                    return (
                        <li
                            key={step.id}
                            role="presentation"
                            className="flex min-w-fit flex-1 items-center gap-1"
                        >
                            <button
                                type="button"
                                role="tab"
                                id={`post-step-${step.id}`}
                                aria-controls={`post-steppanel-${step.id}`}
                                aria-selected={active}
                                aria-label={
                                    spoken
                                        ? `${index + 1}. ${step.label} — ${spoken}`
                                        : `${index + 1}. ${step.label}`
                                }
                                onClick={() => nav.setTab(step.id)}
                                className={cn(
                                    'focus-visible:ring-ring/50 flex min-w-fit flex-1 items-center gap-2.5 rounded-xl border px-3 py-2 text-start transition-[color,background-color,box-shadow] outline-none focus-visible:ring-[3px]',
                                    active
                                        ? 'border-input bg-muted/60 shadow-sm'
                                        : 'hover:bg-muted/40 border-transparent',
                                )}
                            >
                                <StepDisc
                                    status={status}
                                    index={index}
                                    active={active}
                                />
                                <span
                                    className={cn(
                                        'truncate text-sm font-medium',
                                        active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {step.label}
                                </span>
                            </button>
                            {!last && (
                                <span
                                    aria-hidden
                                    className="bg-border hidden h-px w-4 shrink-0 lg:block"
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
