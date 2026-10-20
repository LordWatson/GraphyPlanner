import {
    AlertTriangle,
    ArrowRight,
    Check,
    ChevronDown,
    Circle,
    Wand2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type {
    ChecklistFix,
    ChecklistItem,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Step M-1.6: the live (unsaved-state) evaluation lives in `lib/live-checklist.ts`.
export { liveChecklist } from '@/lib/live-checklist';

/** Short, scannable labels for the server's §6 checklist keys; unknown keys keep the server label. */
const shortLabels: Record<string, string> = {
    caption: 'Every channel has a caption',
    targets: 'At least one channel',
    schedule: 'Every channel has a date & time',
    media: 'Media attached',
    caption_length: 'Captions fit each channel',
    format: 'Formats fit the media',
    media_fit: 'Media within each channel’s limits',
    account_settings: 'Channel settings complete',
    hashtags: 'Hashtags suit each channel',
    links: 'Links will work where posted',
    first_comment: 'First comments supported',
    media_framing: 'Media framed for each channel',
    campaign_hashtags: 'Campaign hashtags included',
};

/** One checklist line: a button that jumps to the field when the editor can, plain text otherwise. */
function JumpRow({
    label,
    onJump,
    children,
}: {
    label: string;
    onJump?: () => void;
    children: ReactNode;
}) {
    if (!onJump) {
        return (
            <span className="flex items-center gap-2.5" title={label}>
                {children}
            </span>
        );
    }

    return (
        <button
            type="button"
            onClick={onJump}
            title={label}
            className="group/jump hover:bg-muted/60 focus-visible:ring-ring/50 -mx-1.5 flex items-center gap-2.5 rounded-lg px-1.5 py-0.5 text-start outline-none focus-visible:ring-[3px]"
        >
            {children}
            <ArrowRight className="text-muted-foreground ms-auto size-3.5 shrink-0 opacity-0 transition-opacity group-hover/jump:opacity-100 group-focus-visible/jump:opacity-100" />
        </button>
    );
}

export function ReadinessChecklist({
    items,
    note,
    onFix,
    onJump,
    title = 'Ready to schedule',
}: {
    items: ChecklistItem[];
    note?: string;
    /** The heading (the grid planner says "Ready for the client"). */
    title?: string;
    /** Applies a one-click fix to the unsaved editor state (omit when read-only). */
    onFix?: (fix: ChecklistFix) => void;
    /** Takes the user to the part of the editor an item is about (omit when read-only). */
    onJump?: (key: string) => void;
}) {
    // Soft checks warn but never count against "ready". Passing soft checks stay out of the way.
    const required = items.filter((item) => !item.soft);
    const open = items.filter((item) => !item.passed);
    const done = items.filter((item) => item.passed && !item.soft);
    const passedCount = required.filter((item) => item.passed).length;
    const allPassed = passedCount === required.length;
    // Done items fold away so the rail only shows what's left; they're one click from view.
    const [showDone, setShowDone] = useState(false);
    const visible = showDone ? [...open, ...done] : open;

    return (
        <section className="border-border bg-card rounded-2xl border p-5">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="text-[15px] font-semibold">
                    {allPassed ? `${title}.` : title}
                </h2>
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
            {allPassed && open.length === 0 && (
                <p className="text-muted-foreground mt-3 text-[13px]">
                    Every check passes — move it along from the workflow above.
                </p>
            )}
            <ul className={cn('grid gap-2.5', visible.length > 0 && 'mt-4')}>
                {visible.map((item) => (
                    <li key={item.key} className="grid gap-1.5 text-sm">
                        <JumpRow
                            label={item.label}
                            onJump={onJump && (() => onJump(item.key))}
                        >
                            {item.passed ? (
                                <span className="bg-success/15 text-success flex size-5 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-3" />
                                    <span className="sr-only">Done:</span>
                                </span>
                            ) : item.soft ? (
                                <span className="text-warning flex size-5 shrink-0 items-center justify-center">
                                    <AlertTriangle className="size-4" />
                                    <span className="sr-only">
                                        Worth a look:
                                    </span>
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
                        </JumpRow>
                        {!item.passed && item.detail && (
                            <ul className="text-muted-foreground ms-7.5 grid gap-1 text-xs leading-snug">
                                {item.detail.split('\n').map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        )}
                        {!item.passed &&
                            onFix &&
                            (item.fixes?.length ?? 0) > 0 && (
                                <div className="ms-7.5 flex flex-wrap gap-1.5">
                                    {item.fixes?.map((fix) => (
                                        <Button
                                            key={`${fix.action}-${fix.social_account_id ?? fix.platform}-${fix.value}`}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => onFix(fix)}
                                        >
                                            <Wand2 className="size-3.5" />
                                            {fix.label}
                                        </Button>
                                    ))}
                                </div>
                            )}
                    </li>
                ))}
            </ul>
            {done.length > 0 && (
                <Button
                    type="button"
                    variant="link"
                    className="mt-3 h-auto p-0 text-xs"
                    onClick={() => setShowDone((current) => !current)}
                    aria-expanded={showDone}
                >
                    <ChevronDown
                        className={cn(
                            'size-3.5 transition-transform',
                            showDone && 'rotate-180',
                        )}
                    />
                    {showDone ? 'Hide' : 'Show'} {done.length} done
                </Button>
            )}
            {note && (
                <p className="text-muted-foreground border-border/70 mt-4 border-t pt-3 text-xs">
                    {note}
                </p>
            )}
        </section>
    );
}
