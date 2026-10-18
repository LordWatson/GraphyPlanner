import { AlertTriangle, Check, Circle } from 'lucide-react';
import type { ChecklistItem } from '@/components/post-editor/types';
import { cn } from '@/lib/utils';

/** Short, scannable labels for the server's §6 checklist keys; unknown keys keep the server label. */
const shortLabels: Record<string, string> = {
    caption: 'Caption written',
    targets: 'At least one channel',
    schedule: 'Every channel has a date & time',
    media: 'Media attached',
    campaign_hashtags: 'Campaign hashtags included',
};

/**
 * Mirrors `EvaluatePostChecklistAction` against the editor's *unsaved* state so the checklist
 * reacts while you type. The server re-runs the real check on save and before scheduling — this
 * is only feedback, never the gate.
 */
export function liveChecklist(
    serverItems: ChecklistItem[],
    state: {
        caption: string;
        selectedAccounts: { is_text_only_capable: boolean }[];
        schedule: { date: string; time: string }[];
        mediaCount: number;
        hashtags?: string[];
        campaignHashtags?: string[];
    },
): ChecklistItem[] {
    const hasTargets = state.selectedAccounts.length > 0;
    const tags = new Set(
        (state.hashtags ?? []).map((tag) =>
            tag.replace(/^#/, '').toLowerCase(),
        ),
    );
    const live: Record<string, boolean> = {
        campaign_hashtags: (state.campaignHashtags ?? []).every((tag) =>
            tags.has(tag.replace(/^#/, '').toLowerCase()),
        ),
        caption: state.caption.trim() !== '',
        targets: hasTargets,
        schedule:
            hasTargets &&
            state.schedule.every((row) => row.date !== '' && row.time !== ''),
        media:
            state.mediaCount > 0 ||
            (hasTargets &&
                state.selectedAccounts.every(
                    (account) => account.is_text_only_capable,
                )),
    };

    return serverItems.map((item) =>
        item.key in live ? { ...item, passed: live[item.key] } : item,
    );
}

export function ReadinessChecklist({
    items,
    note,
}: {
    items: ChecklistItem[];
    note?: string;
}) {
    // Soft checks (C-4.2) warn but never count against "ready".
    const required = items.filter((item) => !item.soft);
    const passedCount = required.filter((item) => item.passed).length;
    const allPassed = passedCount === required.length;

    return (
        <section className="border-border bg-card rounded-2xl border p-5">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="text-[15px] font-semibold">Ready to schedule</h2>
                <span
                    className={cn(
                        'font-mono text-xs tabular-nums',
                        allPassed ? 'text-success' : 'text-muted-foreground',
                    )}
                >
                    {passedCount}/{required.length}
                </span>
            </div>
            <div
                className="bg-muted mt-3 flex h-1 gap-0.5 overflow-hidden rounded-full"
                aria-hidden
            >
                {required.map((item) => (
                    <span
                        key={item.key}
                        className={cn(
                            'h-full flex-1 transition-colors',
                            item.passed ? 'bg-success' : 'bg-transparent',
                        )}
                    />
                ))}
            </div>
            <ul className="mt-4 grid gap-2.5">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex items-center gap-2.5 text-sm"
                        title={item.label}
                    >
                        {item.passed ? (
                            <span className="bg-success/15 text-success flex size-5 shrink-0 items-center justify-center rounded-full">
                                <Check className="size-3" />
                                <span className="sr-only">Done:</span>
                            </span>
                        ) : item.soft ? (
                            <span className="text-warning flex size-5 shrink-0 items-center justify-center">
                                <AlertTriangle className="size-4" />
                                <span className="sr-only">Worth a look:</span>
                            </span>
                        ) : (
                            <span className="text-muted-foreground flex size-5 shrink-0 items-center justify-center">
                                <Circle className="size-4" />
                                <span className="sr-only">To do:</span>
                            </span>
                        )}
                        <span
                            className={
                                item.passed
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            }
                        >
                            {shortLabels[item.key] ?? item.label}
                        </span>
                    </li>
                ))}
            </ul>
            {note && (
                <p className="text-muted-foreground border-border/70 mt-4 border-t pt-3 text-xs">
                    {note}
                </p>
            )}
        </section>
    );
}
